# OrderHub

## What this is

OrderHub is an order processing API. An authenticated user creates an order
from a set of products, the system validates stock, calculates the total,
safely reserves the inventory, and processes the order asynchronously.

The parts worth looking at closely are the order creation flow, in
particular how stock is locked and decremented safely under concurrent
requests, and the tests that verify it.

## Tech stack

Laravel 13, PHP 8.3, MySQL. The queue driver is `database`, so no extra
infrastructure (like Redis) is needed to run it locally.

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
| GET    | `/api/products`        | List available products (paginated)          |
| POST   | `/api/orders`          | Create an order from product and quantity pairs |
| GET    | `/api/orders/{order}`  | View a single order (only the owner can see it) |

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

## How to run the tests

To be filled in once the test suite is written. This section will explain
how to run the tests and what each one is checking.

## Limitations and improvements with more time

- No order cancellation or refund flow.
- No payment gateway integration (the queued job only simulates processing).
- No product search, filtering, or admin management endpoints.
- Single currency, no tax, shipping cost, or discount handling. Adding this
  would mean introducing pricing rules and new columns on the order (subtotal,
  tax, shipping cost, discount) rather than deriving everything from
  `total_amount`.
