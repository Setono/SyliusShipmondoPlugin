# Shipmondo integration

The plugin connects a Sylius store to Shipmondo: Sylius orders become Shipmondo sales orders to be packed and shipped, and Shipmondo's events flow back into Sylius.

## Language

### Uploading orders

**Sales order**:
Shipmondo's record of an order to be packed and shipped, created from a Sylius order.
_Avoid_: Shipmondo order

**Upload**:
Creating the sales order for a Sylius order in Shipmondo.
_Avoid_: Dispatch, sync, export, push

**Upload run**:
One pass of the order upload, which looks up the sales orders of the unlinked orders, then uploads every pending order that qualifies for upload, and last registers the webhooks again if the store's webhooks in Shipmondo no longer match. It stops early, and the remaining orders wait for the next run, after a sales order lookup or an upload that hits a credentials failure, after an upload that is rate limited, after three temporary failures in a row, lookups and uploads alike, with no call Shipmondo answered between them, or after three uploads in a row that make the order failed, with no upload Shipmondo accepted between them. The lookups alone stop after a lookup that is rate limited, or after three lookups in a row that fail in any other way: the pending orders are still uploaded. Three unread successes in a row, with no uploaded order between them, don't stop the run, since Shipmondo has the sales orders, but the run reports them as a problem, as it reports stopping early.
_Avoid_: Sync, batch

**Shipmondo state**:
Where a Sylius order is in its upload: pending, uploading, uploaded, unlinked, failed or cancelled.
_Avoid_: Upload status, sync state

**Pending**:
The order has no sales order yet and will be uploaded by the next upload run once it qualifies.
_Avoid_: New, queued

**Uploading**:
An upload of the order has started or is waiting to be handled, and hasn't finished.
_Avoid_: Dispatching

**Uploaded**:
Shipmondo has a sales order for the order, and the order is linked to it. Only an uploaded order is linked to a sales order.
_Avoid_: Dispatched, synced

**Unlinked**:
Shipmondo may have a sales order for the order, but the order isn't linked to it. Either its upload was an unread success, so Shipmondo created a sales order but which is unknown, or the call that creates the sales order failed temporarily, so Shipmondo may have created one before the failure. It stays unlinked until a sales order lookup settles it.
_Avoid_: Unconfirmed, lost

**Sales order lookup**:
Finding the sales order Shipmondo may have created for an unlinked order, by the exact order id its upload sent. An upload run looks up each order that has been unlinked for at least ten minutes, since Shipmondo lists a new sales order only after a short delay. One sales order found: the order is linked to it and becomes uploaded. None: the call that failed temporarily didn't create one, or someone deleted it in Shipmondo, so the order goes back to pending and is uploaded again, as when a linked order's sales order is deleted. Several: the order stays unlinked, since some may be another shop's. A lookup that fails is tried again by the next upload run.
_Avoid_: Search, sync, reconcile

**Failed**:
The order's upload can't succeed as it is: Shipmondo rejected it, or the order's data couldn't be turned into a sales order. Shipmondo has no sales order for it. It stays failed until an admin retries its upload, which makes it pending again.
_Avoid_: Error, broken

**Cancelled**:
The Sylius order was cancelled, and Shipmondo has no sales order for it: it was never uploaded, or its sales order was deleted. Nothing more happens to it. An order that is cancelled in Sylius becomes cancelled right away when it is pending or failed, once its sales order is deleted when it is uploaded, once a sales order lookup finds its sales order and it is deleted, or finds none, when it is unlinked, and once its upload is settled when it is uploading.

**Upload outcome**:
How one upload went: uploaded, a Shipmondo failure, the order changed while it was uploading (back to pending), or skipped because the order wasn't uploading. It decides the order's Shipmondo state: an upload that is an unread success, or whose call that creates the sales order fails temporarily, makes the order unlinked; an upload that is rejected or not found, or fails unexpectedly in any other way, makes it failed; and any other Shipmondo failure puts it back to pending.

**Rejected upload**:
An upload that Shipmondo rejected or answered with not found. The order becomes failed, and the upload counts toward the upload run's stop rule.

**Shipment template**:
Shipmondo's preset for shipping a sales order: the carrier and product, and the parcels, from a sender country to a receiver country. The admin allows some for each shipping method, and the upload chooses the one that ships from the store's country to the shipping address's country and fits the order's weight best.

**Payment gateway**:
Shipmondo's connection to the store's payment provider, with which Shipmondo can capture a sales order's payment when the order is fulfilled. The admin maps each payment method to one, or to none.

**Gateway payment**:
A payment of a Sylius order that is authorized or completed, made with a payment method mapped to a payment gateway. Shipmondo can capture what the order's gateway payments hold only when they all go through one payment gateway: the sales order then carries that payment gateway, and their sum as its authorized amount. Otherwise it carries neither.

**Stale mapping**:
A payment method mapped to a payment gateway, or a shipping method that allows a shipment template, that no longer exists in Shipmondo, e.g. because it was deleted there. The payment gateway or shipment template it points to is called stale too. It is kept until an admin changes it: the Shipmondo page flags it, and the upload of an order that uses it logs it and uploads the order as before.
_Avoid_: broken mapping, orphaned mapping

**VAT percent**:
The VAT on an amount as a share of the amount excluding VAT, e.g. 0.25 for 25 %, as a sales order states it for each order line, the shipping and the payment. It is 0 when there is no amount excluding VAT, e.g. for a free item.
_Avoid_: tax rate (Sylius's, which is set per tax category and may be included in the price)

### Shipmondo failures

**Shipmondo failure**:
A call to Shipmondo that didn't succeed, sorted into one of the kinds below. Each caller decides what a kind means for it: an upload settles the order's Shipmondo state, the admin explains it, the shop's pickup point search shows that the pickup points couldn't be loaded.

**Credentials failure**:
Shipmondo didn't accept the store's API credentials (401 or 403). It says nothing about the order or the call.

**Rate limited**:
Shipmondo refused the call with 429 Too Many Requests. Like a credentials failure, it says nothing about the call.

**Temporary failure**:
A failure expected to pass: a Shipmondo server error, a network problem, or Shipmondo not answering in time, which includes a read whose answer didn't arrive in full.

**Not found**:
Shipmondo has no such record (404). Deleting a sales order that is not found is not a failure: the sales order is gone, as wanted.

**Rejected**:
Shipmondo refused the call because of something about it, such as invalid data (any other 4xx).
_Avoid_: validation error

**Unexpected failure**:
An answer nothing expects (a redirect, a success to a read that can't be read), or a failure before Shipmondo answered, such as order data a data mapper can't turn into a sales order.

**Unread success**:
Shipmondo answered a call that changes something, such as creating a sales order, with a success, but its answer was cut off, e.g. because it didn't arrive in time, or couldn't be read. Shipmondo did what was asked, but what it answered is unknown. Deleting a sales order that ends in an unread success is not a failure: the sales order is gone, as wanted.
_Avoid_: lost answer

### Pickup points

**Pickup point**:
A carrier's location, often in a shop, where the customer collects the parcel. The customer chooses one in checkout from Shipmondo's pickup points near the shipping address, and it becomes the sales order's service point.
_Avoid_: parcel shop, droppoint, service point (Shipmondo's name for it on a sales order)

**Pickup point delivery**:
A shipping method setting: its shipments are delivered to a pickup point the customer chooses in checkout, searched with the method's carrier. The shipping step doesn't accept such a shipping method without a pickup point, and drops the pickup point chosen for any other shipping method.

### Webhooks

**Webhook**:
Shipmondo's message that something happened to one of its records, named by resource and action, e.g. orders/delete when a sales order is deleted. A webhook about a sales order belongs to the Sylius order linked to that sales order, and to no order if none is, even when its order number matches one: another shop on the same Shipmondo account numbers its orders the same way.
_Avoid_: remote event (Symfony's name for it), callback, notification

**Webhook handler**:
What the plugin does to a Sylius order when a webhook about its sales order arrives, such as shipping the order's shipments when Shipmondo reports the sales order shipped.

**Webhook registration**:
The set of webhooks the store has asked Shipmondo to send it at its endpoint: one for each action a webhook handler handles. Registering makes the store's own webhooks in Shipmondo (the ones named with its shop id or sent to its endpoint) match that set: it adds the missing ones, including any Shipmondo has deactivated, removes the rest, and leaves those that match. Another store on the same Shipmondo account keeps its own. An admin registers once, which sets the endpoint. After that, every upload run registers again at that endpoint whenever the store's webhooks in Shipmondo no longer match, e.g. after an upgrade changes the actions handled, unless the store turns that off. Nothing is registered at an endpoint Shipmondo can't reach, such as one at localhost, so the webhooks registered before are kept.
_Avoid_: subscription sync

**Shop id**:
What tells a store's webhooks apart from those of the other stores on the same Shipmondo account: 8 hexadecimal characters in each webhook's name, derived from the store's webhooks key. Stores with the same webhooks key get the same shop id, so each one's registration replaces the other's webhooks.
