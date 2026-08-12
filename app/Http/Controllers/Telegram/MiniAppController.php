<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;

/**
 * Страница мини-приложения. Отдаётся без проверки доступа намеренно: initData
 * лежит во фрагменте адреса (#tgWebAppData=…) и серверу при первом запросе не
 * виден — его читает уже JS и присылает в каждый запрос к /tg/api. Поэтому сама
 * страница — пустой каркас без данных заказов.
 */
class MiniAppController extends Controller
{
    public function __invoke(): View
    {
        return view('telegram.app', [
            'statuses' => Order::STATUS_LABELS,
            'paymentStatuses' => Order::PAYMENT_STATUS_LABELS,
        ]);
    }
}
