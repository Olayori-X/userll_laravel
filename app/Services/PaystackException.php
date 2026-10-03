<?php

namespace App\Services;

use RuntimeException;

/** Paystack could not be reached or refused a request. Never carries the secret key. */
class PaystackException extends RuntimeException
{
}
