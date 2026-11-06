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
One pass of the order upload, which uploads every pending order that qualifies for upload. It stops early after a credentials failure, a rate-limited upload, or three rejected uploads with no upload between them, and the remaining orders stay pending for the next run.
_Avoid_: Sync, batch

**Shipmondo state**:
Where a Sylius order is in its upload: pending, uploading, uploaded, failed or cancelled.
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

**Failed**:
The order's upload can't succeed as it is: Shipmondo rejected it, or the order's data couldn't be turned into a sales order. It stays failed until an admin steps in.
_Avoid_: Error, broken

**Cancelled**:
The Sylius order was cancelled, and Shipmondo has no sales order for it: it was never uploaded, or its sales order was deleted. Nothing more happens to it. An order that is cancelled in Sylius becomes cancelled right away when it is pending or failed, once its sales order is deleted when it is uploaded, and once its upload is settled when it is uploading.

### Upload failures

**Rejected upload**:
An upload Shipmondo refused because of something about this order, such as invalid data. The order becomes failed.

**Credentials failure**:
An upload Shipmondo refused because it didn't accept the store's API credentials. It says nothing about the order, which goes back to pending.

**Rate-limited upload**:
An upload Shipmondo refused with 429 Too Many Requests. Like a credentials failure, it says nothing about the order, which goes back to pending.

**Temporary failure**:
An upload that failed for a reason expected to pass, such as a Shipmondo server error or a network problem. The order goes back to pending.

**Unexpected failure**:
An upload that failed before Shipmondo answered, or in a way nothing expects, such as order data a data mapper can't turn into a sales order. The order becomes failed.

**Upload outcome**:
How one upload went: uploaded, one of the failures above, the order changed while it was uploading (back to pending), or skipped because the order wasn't uploading. It decides the order's Shipmondo state.

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
The set of webhooks the store has asked Shipmondo to send it: exactly the ones a webhook handler handles. Shipmondo sends the webhooks of the latest registration, so a change to that set takes effect only once the webhooks are registered again.
_Avoid_: subscription sync
