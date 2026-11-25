# A sales order lookup settles every unlinked order, and a temporary failure to create the sales order makes the order unlinked

Shipmondo has no idempotency key and accepts two sales orders with the same order id. A temporary failure of the call that creates the sales order (a 5xx, or no answer, e.g. a timeout) used to put the order back to pending. If Shipmondo had created the sales order before the failure, the next upload run created a second one, which could be shipped twice. Such an upload now makes the order unlinked, as an unread success does, so unlinked means "Shipmondo *may* have a sales order for the order" instead of "most likely has" (ADR 0002). Each upload run looks up the sales order of every order that has been unlinked for at least ten minutes, by the exact order id its upload sent:
- one match links the order, which becomes uploaded;
- no match puts the order back to pending, to be uploaded again, as when a linked order's sales order is deleted in Shipmondo;
- several matches leave it unlinked with an error, since some may be another shop's on the same Shipmondo account.

The run waits ten minutes because Shipmondo lists a new sales order only after a short delay. A Sylius cancel of an unlinked order is left to the lookup: a match is deleted, and with no match the order becomes cancelled.

## Considered Options

- **A new Shipmondo state for "may have a sales order"**, settled by the same lookup: it would keep unlinked's narrower meaning, but add a seventh public state that behaves the same way.
- **Keep a temporary failure pending, and look up before the next upload**: pending would have to remember that its last upload failed temporarily (a new field), and pending would no longer mean "no sales order yet".
- **Look up right after the temporary failure**: Shipmondo lists a new sales order only after a short delay, so the lookup can miss the sales order the failed call just created, and the order is uploaded twice anyway.
- **Keep a temporary failure pending, as before**: the duplicate risk stays.

## Consequences

- An order whose create call failed temporarily is uploaded again at least ten minutes later, not in the next upload run, and a Sylius cancel of it waits for the lookup.
- The lookup matches on the order id alone. Another shop on the same Shipmondo account that numbers its orders the same way can be matched; giving each shop its own order id prefix (the `order_id_prefix` option, #71) avoids that.
- An order with several matches stays unlinked for good, and Shipmondo's webhooks don't reach it: an admin ships and cancels it by hand.
- Every upload run looks up an order with several matches again. Shipmondo allows about 100 sales order searches a minute, so enough of them could rate limit the lookups. A rate limited lookup therefore stops only the lookups, not the uploads, and the lookups go newest first, so such orders can't keep the others from being uploaded or looked up.
- Shipmondo's webhooks about a sales order that arrive while its order is unlinked are lost. The lookup that links the order catches it up instead (#76): it runs the orders/status_update webhook handlers with the sales order it found, so a shipped sales order ships the order, and warns when the sales order's payment may have been captured, voided or refunded meanwhile, since a sales order holds only its payment's current state.
