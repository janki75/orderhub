<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown when an order cannot be created because a business rule failed
 * (a product is inactive, out of stock, or no longer exists). Rendered as
 * a 422 so the caller gets a clear reason instead of a generic error.
 */
class OrderCreationException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
