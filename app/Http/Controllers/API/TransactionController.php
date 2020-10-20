<?php

namespace App\Http\Controllers\API;

use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use Auth;
use Exception;
use Midtrans\Config;
use Midtrans\Snap;

class TransactionController extends Controller
{
    public function all(Request $request)
    {
        $id      = $request->input('id');
        $limit   = $request->input('limit', 6);
        $food_id = $request->input('food_id');
        $status  = $request->input('status');

        if ($id) {
            $transaction = Transaction::with(['food', 'user'])->find($id);
            if ($transaction) {
                return ResponseFormatter::success($transaction, 'Transaction successfully retrieved');
            } else {
                return ResponseFormatter::error(null, 'Transaction not found', 404);
            }
        }

        $transaction = Transaction::with(['food', 'user'])->where('user_id', Auth::user()->id);
        if ($food_id) {
            $transaction->where('food_id', $food_id);
        }

        if ($status) {
            $transaction->where('status', $status);
        }

        return ResponseFormatter::success(
            $transaction->paginate($limit),
            'Transaction successfully retrieved'
        );
    }

    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->update($request->all());

        return ResponseFormatter::success($transaction, 'Transaction successfully updated');
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'food_id'  => 'required|exists:food,id',
            'user_id'  => 'required|exists:user,id',
            'quantity' => 'required',
            'total'    => 'required',
            'status'   => 'required'
        ]);

        $transaction = Transaction::create([
            'food_id'      => $request->food_id,
            'user_id'      => $request->user_id,
            'quantity'     => $request->quantity,
            'total'        => $request->total,
            'status'       => $request->status,
            'paymenet_url' => '',
        ]);

        // Configuration Midtrans
        Config::$serverKey    = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized  = config('services.midtrans.isSanitized');
        Config::$is3ds        = config('services.midtrans.is3ds');

        //
        $transaction = Transaction::with(['food', 'user'])->find($transaction->id);

        // Create Transaction Midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id'     => $transaction->id,
                'gross_amount' => (int) $transaction->total
            ],
            'customer_details' => [
                'first_name' => $transaction->user->name,
                'email'      => $transaction->user->email,
            ],
            'enable_payment' => ['gopay', 'shopeepay', 'bank_payment'],
            'vtweb'          => []
        ];

        // Post to Midtrans
        try {
            // Get Page Payment Midtrans
            $paymentUrl               = Snap::createTransaction($midtrans)->redirect_url;
            $transaction->payment_url = $paymentUrl;
            $transaction->save();

            // Callback data to API
            return ResponseFormatter::success($transaction, 'Transaction successfully');
        } catch (Exception $error) {
            return ResponseFormatter::error($error->getMessage(), 'Transaction Failled');
        }
    }
}
