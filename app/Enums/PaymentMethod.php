<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cod = 'cod';
    case BankTransfer = 'bank_transfer';
    case Ewallet = 'ewallet';
    case Card = 'card';
}
