<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Rifa;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Inertia\Inertia;

class OrderController extends Controller
{
    /** @var int */
    private const RIFA_EXPIRE_AT_MINUTES_DEFAULT = 60;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $result = Order::with([
            'rifa' => fn ($query) => $query->select('id', 'title', 'price', 'slug'),
        ])
        ->where('id', $id)
        ->first();

        if ($result === null) {
            return redirect('/');
        }

        if (now() > Carbon::parse($result->expire_at)) {
            return redirect(route('rifas.show', ['rifa' => $result->rifa]));
        }

        $rifa = $result->rifa;

        $order = $result->makeHidden('rifa');
        $order->transaction_amount = $rifa->price * count($order->numbers_reserved);
        $order->expire_at = Carbon::parse($order->expire_at);

        return inertia('Order/PsrResume', [
            'order' => $order,
            'rifa' => $rifa
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrderRequest $request)
    {
        $rifaId = $request->input('rifa');
        $rifa = Rifa::findOrFail($rifaId);

        $quantity = $request->input('quantity');

        $rifaNumbers = collect()->range(0, $rifa->total_numbers_available - 1);

        $rifaNumbersUnavailable = Order::select('numbers_reserved')
            ->where('rifa_id', $rifa->id)
            ->lazy(100)
            ->chunk(100)
            ->map(function (\Illuminate\Support\LazyCollection $orders) {
                /* numbers_reserved é um array contendo os números gerados */
                return collect($orders)->map(fn ($order) => $order->numbers_reserved);
            })
            ->flatten();

        $rifaNumbersAvailable = $rifaNumbers->diff($rifaNumbersUnavailable)
            ->shuffle();

        if ($rifaNumbersAvailable->count() < $quantity) {
            return abort(409, 'Não foi gerado todos os números');
        }

        $rifaRandomNumbers = $rifaNumbersAvailable->splice(0, $quantity)
            ->map(fn ($number) => str_pad($number, 4, "0", STR_PAD_LEFT))
            ->sort();

        $order = false;

        DB::transaction(function () use ($rifaRandomNumbers, $request, $rifa, &$order) {
            $order = new Order();
            $order->customer_fullname = $request->input('fullname');
            $order->customer_email = $request->input('email');
            $order->customer_telephone = $request->input('telephone');
            $order->rifa_id = $rifa->id;
            $order->numbers_reserved = $rifaRandomNumbers->values();
            $order->status = Order::STATUS_RESERVED;
            $order->expire_at = now()->addMinutes(env('RIFA_EXPIRE_AT_MINUTES', self::RIFA_EXPIRE_AT_MINUTES_DEFAULT));
            $order->saveOrFail();
        });

        if ($order instanceof Order) {
            return Inertia::location(route('orders.show', [ $order->id ]));
        }

        return abort(500);
    }
}
