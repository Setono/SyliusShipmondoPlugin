import fs from 'node:fs';
import path from 'node:path';
import { BrowserContext } from '@playwright/test';
import { applicationDir, baseURL, stubLogFile } from './config';

/**
 * Helpers for the stubbed Shipmondo API of the `e2e` environment (tests/Application/E2E/StubShipmondoHttpClient.php):
 * the server runs the real plugin and the real Shipmondo SDK, only the SDK's HTTP transport is replaced.
 */

export type StubScenario = 'empty' | 'unauthorized';

/** Makes every Shipmondo API call triggered by this browser context answer with the given scenario */
export async function useStubScenario(context: BrowserContext, scenario: StubScenario): Promise<void> {
    await context.addCookies([{ name: 'shipmondo_stub', value: scenario, url: baseURL }]);
}

/** The stub answers DELETE /sales_orders/{id} by the id's prefix */
export function shipmondoSalesOrderId(outcome: 'deleted' | 'not_found' | 'server_error'): number {
    const random = String(Math.floor(Math.random() * 1_000_000)).padStart(6, '0');

    return Number({ deleted: `1${random}`, not_found: `404${random}`, server_error: `500${random}` }[outcome]);
}

export interface StubLogEntry {
    scenario: string | null;
    method: string;
    path: string;
    query: Record<string, string>;
    body: unknown;
}

/**
 * The requests the stub received after a point in time. Take a mark before the action and read the log
 * after it; filter the entries, because tests in other workers append to the same log.
 */
export class StubLog {
    private constructor(private readonly offset: number) {}

    static mark(): StubLog {
        return new StubLog(fs.existsSync(stubLogFile) ? fs.statSync(stubLogFile).size : 0);
    }

    entries(): StubLogEntry[] {
        if (!fs.existsSync(stubLogFile)) {
            return [];
        }

        return fs
            .readFileSync(stubLogFile)
            .subarray(this.offset)
            .toString('utf8')
            .split('\n')
            .filter((line) => line.trim() !== '')
            .map((line) => JSON.parse(line) as StubLogEntry);
    }

    requests(method: string, pathPattern: RegExp): StubLogEntry[] {
        return this.entries().filter((entry) => entry.method === method && pathPattern.test(entry.path));
    }
}

function readResponse<T>(name: string): T {
    return JSON.parse(fs.readFileSync(path.join(applicationDir, 'E2E/shipmondo', `${name}.json`), 'utf8')) as T;
}

/** The pickup points the stub returns (captured from the Shipmondo API by the SDK's test suite) */
export const pickupPoints = readResponse<
    Array<{ id: string; number: string; company_name: string; name: string; address: string; address2: string; zipcode: string; city: string; country: string; opening_hours: string[] }>
>('pickup_points');

export const paymentGateways = readResponse<Array<{ id: number; name: string }>>('payment_gateways');

export const shipmentTemplates = readResponse<Array<{ id: number; name: string }>>('shipment_templates');

export const existingWebhooks = readResponse<Array<{ id: number; name: string }>>('webhooks');
