<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    public function store(CreateOrderRequest $request)
    {
        $order = $this->orderService->createOrder(
            $request->user(),
            $request->validated()['items']
        );

        return (new OrderResource($order->load('items.product')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('items.product'));
    }
}
