# An unread success makes the order unlinked, not failed

When Shipmondo answers the upload of an order with a success that is cut off or can't be read, Shipmondo has created the sales order, but the plugin doesn't know its id. Such an upload used to make the order failed, like any unexpected failure. But the plugin acts on a failed order as one Shipmondo has no sales order for: a Sylius cancel makes it cancelled right away, without touching Shipmondo, and an admin can retry its upload, which would create a second sales order that could be shipped twice. So an unread success is its own kind of Shipmondo failure, and it makes the order unlinked, a Shipmondo state of its own. Failed always means Shipmondo has no sales order. Nothing moves an order out of unlinked until the plugin can look its sales order up in Shipmondo by order number. A Sylius cancel leaves an unlinked order unlinked and tells the admin to cancel the sales order in Shipmondo by hand.

## Considered Options

- **Keep it failed, and store the failure kind on the order**: the retry refuses, or warns about, a failed order whose upload was an unread success. Failed would keep meaning two things, and a Sylius cancel would still make the order cancelled while Shipmondo keeps the sales order.
- **Keep it failed, and warn on every retry**: the admin checks Shipmondo by hand before each retry, and a missed check ships the order twice.
- **Let an admin link the order, or retry it after checking Shipmondo**: rejected in favour of an automatic lookup by order number once the SDK can list sales orders, so an admin doesn't have to search Shipmondo.

## Consequences

- The Shipmondo states and the order workflow's transitions are public: the `unlinked` state and the `complete_upload_unlinked` transition can't be renamed or dropped without a BC break once 2.x is released.
- Until the lookup exists, an unlinked order stays unlinked, and the admin finds its sales order in Shipmondo by hand.
- A sales order deletion that ends in an unread success counts as deleted.
