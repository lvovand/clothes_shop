<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\YandexDelivery\YandexDeliveryClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Точки сдачи посылок Яндекс Доставки для карты в админке. */
class YandexDeliveryPointsController extends Controller
{
    public function __invoke(Request $request, YandexDeliveryClient $client): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:255'],
            // Токен может быть только что вписан в форму и ещё не сохранён.
            'token' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($data['token'])) {
            $client = new YandexDeliveryClient($data['token'], null, null, null);
        }

        $geoId = $client->findGeoId(trim($data['city']));

        if (! $geoId) {
            return response()->json(['ok' => false, 'points' => []]);
        }

        // Склады Яндекса первыми: это самый частый вариант сдачи заказов.
        $points = collect($client->pickupPoints($geoId, forDropoff: true))
            ->sortBy(fn ($point) => ($point['type'] === 'warehouse' ? 0 : 1).$point['address'])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'points' => $points]);
    }
}
