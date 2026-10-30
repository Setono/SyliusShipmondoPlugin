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
One pass of the order upload, which uploads every pending order that qualifies for upload.
_Avoid_: Sync, batch

**Shipmondo state**:
Where a Sylius order is in its upload: pending, uploading, uploaded or failed.
_Avoid_: Upload status, sync state

**Pending**:
The order has no sales order yet and will be uploaded by the next upload run once it qualifies.
_Avoid_: New, queued

**Uploading**:
An upload of the order has started or is waiting to be handled, and hasn't finished.
_Avoid_: Dispatching

**Uploaded**:
Shipmondo has a sales order for the order.
_Avoid_: Dispatched, synced

**Failed**:
The order's upload can't succeed as it is: Shipmondo rejected it, or the order's data couldn't be turned into a sales order. It stays failed until an admin steps in.
_Avoid_: Error, broken

### Upload failures

**Rejected upload**:
An upload Shipmondo refused because of something about this order, such as invalid data. The order becomes failed.

**Credentials failure**:
An upload Shipmondo refused because it didn't accept the store's API credentials. It says nothing about the order, which goes back to pending.

**Temporary failure**:
An upload that failed for a reason expected to pass, such as a Shipmondo server error or a network problem. The order goes back to pending.
