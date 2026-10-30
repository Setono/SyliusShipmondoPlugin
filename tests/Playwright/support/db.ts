import { randomUUID } from 'node:crypto';
import mysql, { ResultSetHeader, RowDataPacket } from 'mysql2/promise';
import { databaseUrl, pickupPointShippingMethod, regularShippingMethod } from './config';

/**
 * Direct access to the e2e database, to arrange state the UI cannot (e.g. an order that was uploaded to
 * Shipmondo) and to assert what the UI persisted. Each call uses its own connection, so specs running in
 * parallel workers don't share any.
 */
async function withConnection<T>(callback: (connection: mysql.Connection) => Promise<T>): Promise<T> {
    const connection = await mysql.createConnection(databaseUrl);

    try {
        return await callback(connection);
    } finally {
        await connection.end();
    }
}

export async function select<T extends RowDataPacket>(sql: string, params: unknown[] = []): Promise<T[]> {
    return withConnection(async (connection) => {
        const [rows] = await connection.query<T[]>(sql, params);

        return rows;
    });
}

export async function execute(sql: string, params: unknown[] = []): Promise<number> {
    return withConnection(async (connection) => {
        const [result] = await connection.query<ResultSetHeader>(sql, params);

        return result.affectedRows;
    });
}

/**
 * Arranges the two fixture shipping methods the specs use (idempotent): both enabled and available worldwide
 * (the fixtures give shipping methods a random zone), one of them a pickup point shipping method
 */
export async function arrangeShippingMethods(): Promise<void> {
    const world = await select<RowDataPacket>("SELECT id FROM sylius_zone WHERE code = 'WORLD'");
    if (world.length === 0) {
        throw new Error('The fixture zone WORLD does not exist. Reset the database with "npm run db:reset".');
    }

    const codes = [pickupPointShippingMethod.code, regularShippingMethod.code];
    await execute(
        `UPDATE sylius_shipping_method
         SET zone_id = ?, is_enabled = 1, archived_at = NULL,
             pickupPointDelivery = (code = ?), carrierCode = IF(code = ?, ?, NULL)
         WHERE code IN (?, ?)`,
        [world[0].id, pickupPointShippingMethod.code, pickupPointShippingMethod.code, pickupPointShippingMethod.carrierCode, ...codes],
    );

    const arranged = await select<RowDataPacket>('SELECT code FROM sylius_shipping_method WHERE code IN (?, ?) AND zone_id = ?', [...codes, world[0].id]);
    if (arranged.length !== codes.length) {
        throw new Error(`The fixture shipping methods ${codes.join(' and ')} are missing. Reset the database with "npm run db:reset".`);
    }
}

export interface ClaimedOrder {
    id: number;
    number: string;
}

/**
 * Atomically claims a completed fixture order that no other test has claimed, and gives it the given
 * Shipmondo id and state. The claim is recorded in the order notes, so parallel workers never get the
 * same order, and claimed orders are not reused by later runs.
 */
export async function claimOrder(shipmondo: { id?: number; state?: string } = {}): Promise<ClaimedOrder> {
    const marker = `e2e:${randomUUID()}`;

    const claimed = await execute(
        `UPDATE sylius_order
         SET notes = ?, shipmondoId = ?, shipmondoState = ?
         WHERE state = 'new' AND checkout_state = 'completed' AND shipmondoId IS NULL AND shipmondoState = 'pending'
           AND (notes IS NULL OR notes NOT LIKE 'e2e:%')
         ORDER BY id
         LIMIT 1`,
        [marker, shipmondo.id ?? null, shipmondo.state ?? 'pending'],
    );

    if (claimed !== 1) {
        throw new Error('There is no unclaimed fixture order left. Reset the database with "npm run db:reset".');
    }

    const [order] = await select<RowDataPacket & ClaimedOrder>('SELECT id, number FROM sylius_order WHERE notes = ?', [marker]);

    return { id: Number(order.id), number: String(order.number) };
}

/** Undoes a claim that left the order untouched (apart from its Shipmondo state), so later runs can use the order again */
export async function releaseOrder(order: ClaimedOrder): Promise<void> {
    await execute("UPDATE sylius_order SET notes = NULL, shipmondoId = NULL, shipmondoState = 'pending' WHERE id = ? AND state = 'new'", [order.id]);
}

export async function findOrder(id: number): Promise<{ state: string; shipmondoId: number | null }> {
    const [order] = await select<RowDataPacket>('SELECT state, shipmondoId FROM sylius_order WHERE id = ?', [id]);

    return { state: order.state, shipmondoId: order.shipmondoId === null ? null : Number(order.shipmondoId) };
}

/**
 * The pickup point stored on the shipment of the customer's latest order (a cart or a placed order)
 */
export async function findPickupPointOfLatestOrder(customerEmail: string): Promise<{ orderState: string; pickupPoint: Record<string, unknown> | null }> {
    const rows = await select<RowDataPacket>(
        `SELECT o.state AS orderState, s.shipmondoPickupPoint AS pickupPoint
         FROM sylius_order o
         JOIN sylius_customer c ON c.id = o.customer_id
         JOIN sylius_shipment s ON s.order_id = o.id
         WHERE c.email = ?
         ORDER BY o.id DESC
         LIMIT 1`,
        [customerEmail],
    );

    if (rows.length === 0) {
        throw new Error(`No order found for the customer ${customerEmail}`);
    }

    const { orderState, pickupPoint } = rows[0];

    return {
        orderState,
        pickupPoint: pickupPoint === null ? null : typeof pickupPoint === 'string' ? JSON.parse(pickupPoint) : pickupPoint,
    };
}

/** Removes every payment method and shipping method mapping made on the admin Shipmondo page */
export async function resetShipmondoMappings(): Promise<void> {
    await execute('UPDATE sylius_payment_method SET shipmondoId = NULL');
    await execute('UPDATE sylius_shipping_method SET allowedShipmentTemplates = NULL');
}

export async function forgetRegisteredWebhooks(): Promise<void> {
    await execute('DELETE FROM setono_sylius_shipmondo__registered_webhooks');
}

/** A slug of a product that can be added to the cart */
export async function findProductSlug(): Promise<string> {
    const [product] = await select<RowDataPacket>(
        `SELECT t.slug
         FROM sylius_product p
         JOIN sylius_product_translation t ON t.translatable_id = p.id AND t.locale = 'en_US'
         WHERE p.enabled = 1
         ORDER BY p.code
         LIMIT 1`,
    );

    return product.slug;
}
