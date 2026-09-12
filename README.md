# OrderHub

## What this is

OrderHub is an order processing API. An authenticated user creates an order
from a set of products, the system validates stock, calculates the total,
safely reserves the inventory, and processes the order asynchronously.

The parts worth looking at closely are the order creation flow, in
particular how stock is locked and decremented safely under concurrent
requests, and the tests that verify it.

## Tech stack

Laravel 13, PHP 8.3, MySQL, Pest for tests. The queue driver is `database`,
so no extra infrastructure (like Redis) is needed to run it locally.

## Setup and run

```bash
composer install
cp .env.example .env
```

Set the database values in `.env` to match your local MySQL instance:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=orderhub
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Then:

```bash
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

The seeder creates a demo user and 20 sample products, so there is data to
work with right away. Order processing happens in a queued job, so run a
worker in a separate terminal to see orders move from pending to completed:

```bash
php artisan queue:work
```

## API endpoints

| Method | Endpoint              | Purpose                                      |
|--------|------------------------|-----------------------------------------------|
| POST   | `/api/login`           | Exchange email and password for an API token |
| GET    | `/api/products`        | List available products (paginated)          |
| POST   | `/api/orders`          | Create an order from product SKU and quantity pairs |
| GET    | `/api/orders/{order_number}` | View a single order (only the owner can see it) |

All endpoints except `/api/login` require a `Authorization: Bearer <token>`
header. The seeded demo user logs in with `demo@example.com` and password
`password`:

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" \
  -d "email=demo@example.com" \
  -d "password=password"
```

Creating an order takes a product's `sku` (from `GET /api/products`), not its
internal id:

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"sku": "LOG-12345", "quantity": 2}]}'
```

## Scope

Intentionally small and focused: order creation with safe inventory
reservation, total calculation, asynchronous post-processing, ownership
based authorization, and tests covering all of that.

Out of scope: cancelling or refunding an order, payment gateway
integration, product search and filtering, a frontend, and any admin or
product management screen. Reasoning for each is below.

## Assumptions

**Order items store the price at the time of purchase.** `unit_price` on
`order_items` is captured when the order is created and does not change if
the product's price changes later. An order should always show what the
customer actually paid, not today's price.

**Products cannot be deleted while orders reference them.** The foreign key
from `order_items` to `products` is not set to cascade on delete, so a
product that appears in a past order cannot be hard deleted. That protects
order history from silently losing data.

**SKU is the unique identifier for a product, not the name.** Product names
can legitimately repeat, for example two different variants or a
re-listed item, so enforcing uniqueness on `name` would have been wrong.
`sku` is the real business key: restocking a product means increasing the
`stock` value on the existing SKU, not creating a new row.

**No slug column.** A slug exists to give a public facing page a readable
URL. There is no product page here, so there is nothing that would use one.

**Orders have a generated `order_number`, separate from the database `id`.**
The auto increment id should never be handed to a customer or support agent
as a reference, since it exposes how many orders exist and is guessable.
`order_number` is generated internally when the order is created and is not
mass assignable, so it can only ever be set by the system, never by request
input.

**Neither `products.id` nor `orders.id` is ever exposed or accepted by the
API.** `GET /api/orders/{order_number}` looks orders up by `order_number`,
and order creation looks products up by `sku`. Both endpoints reject the
internal id entirely, since it should never leave the application. This was
a deliberate choice over adding a package like hashids to obfuscate the raw
id: hashids only hides a number, it does not replace real authorization
(the ownership policy still has to exist regardless), and the result is
meaningless to a human. SKU and order_number are real business identifiers
that a customer or support agent can actually reference, and both already
existed for other reasons, so no extra dependency was needed.

**No subtotal, tax, shipping cost, or discount columns.** The feature
currently does not cover tax, shipping, or discount logic, so these columns
were left out rather than storing values that would always be zero.
`total_amount` is the sum of the order items. This is a natural area to
expand later, once pricing rules for tax jurisdictions, shipping rates, and
discount validation are defined.

**No shipping or billing address.** There is no shipping or fulfillment flow
in this feature (no delivery status, no cancellation before shipping), so
these fields would capture input that is never read or acted on again.
Collecting address data without a feature that uses it is worse practice
than leaving it out.

**Each product in an order request is assumed to be unique.** It is assumed
that the frontend or calling client already merges a repeated product into
a single line with a combined quantity, the way a normal cart does before
checkout. This API does not merge duplicate line items itself, it rejects
the request if the same product's sku appears twice.

**There is no registration endpoint.** Authentication is handled with
Sanctum tokens through a single login endpoint, and the seeder creates a
demo user to log in with. Building account registration, password resets,
and similar account management is a separate concern from order processing
and was left out.

**No cancel or refund flow.** Cancelling an order and restoring stock has
its own state transitions and its own tests, and would not add anything new
to the core problem this project demonstrates (safe concurrent inventory
handling). Noted as a future improvement instead.

**No payment gateway.** Order processing is simulated inside a queued job
(for example, marking the order as completed). A real payment provider is
unrelated to the core problem here.

## Key technical decisions

**Stock is checked and decremented inside a database transaction, using a
row lock (`lockForUpdate()`) on the product.** This is what prevents two
simultaneous orders for the last unit of a product from both succeeding.
Without the lock, both requests could read the same stock value before
either one writes back, and the store would oversell.

**Stock changes happen synchronously, only the follow up processing is
asynchronous.** If the stock decrement itself were pushed onto a queue, two
requests could both pass validation before either job actually runs, which
brings back the exact overselling problem the locking is meant to solve. So
the transaction and the lock happen inside the request, and only the non
critical work (like marking the order completed) is deferred to a job.

**Order creation logic lives in a service class, not the controller.** This
keeps the controller focused on handling the HTTP request and response,
while the business rules (validating stock, calculating totals, creating
the order) live somewhere that can be tested directly and reused if needed.

**Order status is a backed PHP enum, not a plain string.** This avoids
typos or invalid status values ending up in the database, since the set of
valid values is defined once in code.

**Product rows are locked in a consistent order (ascending sku)
within a single order.** If two multi-item orders locked the same products
in a different order from each other, they could deadlock. Sorting by
sku first means every request locks rows in the same sequence.

**A product can only appear once per order request.** A real cart should
merge duplicate items into a single line with a combined quantity before
checkout, so a request with the same sku twice means the client has a bug,
not a valid order. It is rejected rather than silently merged: once at the
request validation layer (a distinct rule on items.*.sku, so the caller
gets a normal 422 validation error), and again inside the service itself as
a safety net for any other caller that skips that validation.

**Item quantity is checked to be at least 1 inside the service, not only in
the FormRequest.** Eloquent's decrement() negates whatever value it is
given, so a negative quantity that reached it would increase stock instead
of reducing it. Since that is a real business rule violation and not just
an input formatting issue, it is enforced in the service itself rather than
relying only on request validation to catch it.

**Business rule failures (inactive product, insufficient stock) throw a
dedicated exception with its own render method**, rather than being handled
with manual response building in the controller. This keeps the service
focused on business logic while still returning a clear 422 with a specific
message.

**Order ownership is enforced with a policy, not a manual check in the
controller.** `OrderPolicy::view()` compares the authenticated user to the
order's owner, and Laravel resolves it automatically by convention since it
lives in `App\Policies` under the matching name. This keeps the
authorization rule in one place instead of repeated inline checks.

## How to run the tests

```bash
php artisan test
```

`tests/Feature/OrderServiceTest.php` covers the order creation logic
directly: correct total calculation and stock decrement, the job being
dispatched only after a successful order, insufficient stock, an inactive
product, a full rollback when one item in a multi item order fails, a
duplicate sku being rejected, an empty item list, and a zero or negative
quantity.

The lock itself (`lockForUpdate()` preventing two simultaneous requests from
overselling the same product) is not exercised by an automated test, since
simulating two real concurrent connections inside a single synchronous
PHPUnit process is impractical. That part is verified by reading the code
path rather than by a test.

`tests/Feature/AuthTest.php`, `ProductApiTest.php`, and `OrderApiTest.php`
cover the HTTP layer on top of that: a guest is rejected with 401 on every
protected route, login succeeds or fails correctly, order creation
validation errors surface as normal 422 responses (empty items, duplicate
sku, non positive quantity, unknown sku), an insufficient stock error
surfaces as a clear message, a user can view their own order by
order_number, a user gets 403 viewing someone else's order, a nonexistent
order_number returns 404, and no response ever exposes an internal id.


## Limitations and improvements with more time

- No order cancellation or refund flow.
- No payment gateway integration (the queued job only simulates processing).
- No product search, filtering, or admin management endpoints.
- Single currency, no tax, shipping cost, or discount handling. Adding this
  would mean introducing pricing rules and new columns on the order (subtotal,
  tax, shipping cost, discount) rather than deriving everything from
  `total_amount`.
