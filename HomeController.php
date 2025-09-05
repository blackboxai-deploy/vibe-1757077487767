<?php

namespace App\Http\Controllers;

use App\Libraries\Tokopay;
use App\Libraries\Paydisini;
use App\Libraries\Tripay;
use App\Models\ConfigWebsite;
use App\Models\Feedback;
use App\Models\History;
use App\Models\Paket;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Stok;
use App\Models\Voucher;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return view('home.index');
    }
    public function LiveSearch(Request $request)
    {
        $product = Product::where('name', 'LIKE', '%' . $request->to_search . '%')->get();
        if ($product->first()) { // Fixed: Added parentheses for method call
            return response()->json([
                'status' => true,
                'message' => 'berhasil mendapatkan data',
                'data' => $product,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'No product found',
            ]);
        }
    }
    public function Ratings()
    {
        return view('home.ratings');
    }
    public function Winrate()
    {
        return view('home.winrate');
    }
    public function MagicWheel()
    {
        return view('home.magic-wheel');
    }
    public function Zodiac()
    {
        return view('home.zodiac');
    }
    public function Search()
    {
        return view('home.search');
    }
    public function DaftarHarga()
    {
        return view('home.daftar-harga');
    }
    public function SearchInvoice(History $history)
    {
        return redirect()->route('invoice', $history->invoice_id);
    }
    public function RedirectPay(History $history)
    {
        $cookie_name = 'ORDERID';
        $cookie_value = $history->invoice_id;
        $expiry_time = time() + 3600; // 3600 = 1 hour
        setcookie($cookie_name, $cookie_value, $expiry_time, '/');
        return redirect($history->pay_url);
    }
    public function ReturnInvoice()
    {
        if (!isset($_COOKIE['ORDERID'])) {
            return redirect('/');
        } else {
            $history = History::where('invoice_id', $_COOKIE['ORDERID'])->first();
            if ($history) {
                if ($history->status == 'success' or $history->status == 'failed') {
                    setcookie("ORDERID", "", time() - 3600);
                    return redirect('invoice/' . $_COOKIE['ORDERID']); // Fixed: Removed extra semicolon
                } else {
                    return redirect('invoice/' . $_COOKIE['ORDERID']); // Fixed: Removed extra semicolon
                }
            }
        }
    }
    public function order(Product $product)
    {
        return view('home.order', compact('product'));
    }
    public function harga(Request $request)
    {
        if ($request->product == 'api') {
            $paket = Paket::where('id', $request->nominal)->first();
        } else {
            $paket = Stok::where('id', $request->nominal)->first();
        }
        if ($paket) {
            $payment = Payment::where('status', 'active')->get();
            $data = [];
            if (isset($request->limit)) {
                $limit = $request->limit;
            } else {
                $limit = 1;
            }
            if ($request->product == 'api') {
                if (
                    $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
                    $paket->start_flash_sale < date('Y-m-d H:i:s') &&
                    $paket->end_flash_sale > date('Y-m-d H:i:s')
                ) {
                    $harga = $paket->flash_sale;
                } elseif ($paket->diskon != 0) {
                    $harga = $paket->diskon;
                } else {
                    $harga = $paket->harga;
                }
            } else {
                $harga = $paket->price;
            }
            foreach ($payment as $row) {
                if ($row->type_fee == 'fix') {
                    $data[] = [
                        'harga' => 'Rp. ' . number_format(($harga + $row->fee) * $limit, 0, ',', '.'),
                        'payment' => $row->code,
                    ];
                } else {
                    $data[] = [
                        'harga' => 'Rp. ' . number_format(((($harga * $row->fee) / 100) * $limit) + $harga, 0, ',', '.'),
                        'payment' => $row->code,
                    ];
                }
            }
            return response()->json([
                'status' => true,
                'message' => 'berhasil mendapatkan data',
                'data' => $data,
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'No product found',
            ]);
        }
    }
    public function CheckVoucher(Request $request)
    {
        $paket = Paket::find($request->service);
        if ($paket) {
            $voucher = Voucher::where('code', $request->voucher)->first();
            if ($voucher) {
                if ($voucher->status != 'active') {
                    return response()->json([
                        'status' => false,
                        'message' => 'Voucher sudah tidak aktif',
                    ]);
                } elseif ($voucher->stock == 0 or $voucher->stock < 1) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Voucher sudah habis',
                    ]);
                } elseif ($voucher->expired_at < date('Y-m-d H:i:s')) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Voucher sudah kadaluarsa',
                    ]);
                } else {
                    if (isset($request->limit)) {
                        $limit = $request->limit;
                    } else {
                        $limit = 1;
                    }

                    if (
                        $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
                        $paket->start_flash_sale < date('Y-m-d H:i:s') &&
                        $paket->end_flash_sale > date('Y-m-d H:i:s')
                    ) {
                        $harga = $paket->flash_sale;
                    } elseif ($paket->diskon != 0) {
                        $harga = $paket->diskon;
                    } else {
                        $harga = $paket->harga;
                    }
                    $harga = $harga * $limit;
                    if ($voucher) {
                        if ($voucher->type_disc == 'fix') {
                            $total = ($harga - $voucher->nominal);
                        } else {
                            $total = ($harga - (($harga * $voucher->nominal) / 100));
                        }
                    } else {
                        $total = $harga;
                    }
                    $payment = Payment::where('status', 'active')->get();
                    $data = [];
                    foreach ($payment as $row) {
                        if ($row->type_fee == 'fix') {
                            $data[] = [
                                'harga' => 'Rp. ' . number_format($total + $row->fee, 0, ',', '.'),
                                'payment' => $row->code,
                            ];
                        } else {
                            $data[] = [
                                'harga' => 'Rp. ' . number_format(($total * $row->fee) / 100, 0, ',', '.'),
                                'payment' => $row->code,
                            ];
                        }
                    }
                    return response()->json([
                        'status' => true,
                        'message' => 'Voucher berhasil digunakan',
                        'harga' => 'Rp. ' . number_format($voucher->nominal, 0, ',', '.') . ',-',
                        'data' => $data,
                    ]);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Voucher tidak ditemukan',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'No product found',
            ]);
        }
    }
    public function KonfirmasiData(Request $request)
    {
        $paket = Paket::find($request->service);
        $payment = Payment::where('code', $request->pembayaran)->first();
        $voucher = Voucher::where('code', $request->voucher)->first();
        if ($request->mode == 'paket') {
            $paket = $paket;
        } else {
            $paket = Stok::find($request->service);
        }
        if ($paket) {
            $product = Product::find($paket->product_id);
            if ($product) {
                if ($payment) {
                    if ($request->data == null) {
                        return response()->json([
                            'status' => false,
                            'data' => 'Nama tidak boleh kosong',
                        ]);
                    }
                    // if ($payment->category == 'EWALLET') {
                    //     if ($request->ewallet_phone == null) {
                    //         return response()->json([
                    //             'status' => false,
                    //             'data' => 'Nomor Ewallet tidak boleh kosong!'
                    //         ]);
                    //     } elseif (strlen($request->ewallet_phone < 10)) {

                    //         return response()->json([
                    //             'status' => false,
                    //             'data' => 'Nomor Ewallet anda kurang dari 10 digit'
                    //         ]);
                    //     }
                    // }
                    if ($voucher) {
                        if ($voucher->status != 'active') {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah tidak aktif',
                            ]);
                        } elseif ($voucher->stock == 0 or $voucher->stock < 1) {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah habis',
                            ]);
                        } elseif ($voucher->expired_at < date('Y-m-d H:i:s')) {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah kadaluarsa',
                            ]);
                        } else {
                            return $this->results($request, $product, $payment, $voucher, $paket);
                        }
                    } else {
                        return $this->results($request, $product, $payment, $voucher, $paket);
                    }
                } else {
                    return response()->json([
                        'status' => false,
                        'data' => 'Payment not found',
                    ]);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'data' => 'Product not found',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'data' => 'Paket not found',
            ]);
        }
    }
    function results($request, $product, $payment, $voucher, $paket)
    {
        $payment->name = strtoupper($payment->name);
        if (isset($request->limit)) {
            $limit = $request->limit;
        } else {
            $limit = 1;
        }
        if (
            $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
            $paket->start_flash_sale < date('Y-m-d H:i:s') &&
            $paket->end_flash_sale > date('Y-m-d H:i:s')
        ) {
            $harga = $paket->flash_sale;
        } elseif ($paket->diskon != 0) {
            $harga = $paket->diskon;
        } else {
            $harga = $paket->harga ?? $paket->price;
        }
        if ($payment->type_fee == 'fix') {
            $hrga = $harga + $payment->fee;
        } else {
            $hrga = (($harga * $payment->fee) / 100) + $harga;
        }
        $harga = $hrga * $limit;
        if ($voucher) {
            if ($voucher->type_disc == 'fix') {
                $total = ($harga - $voucher->nominal);
            } else {
                $total = ($harga - (($harga * $voucher->nominal) / 100));
            }
        } else {
            $total = $harga;
        }
        $harga = number_format($total, 0, ',', '.');
        if ($voucher) {
            $text = '<strike class="text-danger"> Rp ' . number_format($hrga * $limit, 0, ',', '.') . ',-</strike> <br> Rp ' . $harga . ',-';
        } elseif (
            $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
            $paket->start_flash_sale < date('Y-m-d H:i:s') &&
            $paket->end_flash_sale > date('Y-m-d H:i:s')
        ) {
            $text = '<strike class="text-danger"> Rp ' . number_format($paket->harga ?? $paket->price * $limit, 0, ',', '.') . ',-</strike> <br> Rp ' . $harga . ',-';
        } elseif ($paket->diskon != 0) {
            $text = '<strike class="text-danger"> Rp ' . number_format($paket->harga ?? $paket->price * $limit, 0, ',', '.') . ',-</strike> <br> Rp ' . $harga . ',-';
        } else {
            $text = 'Rp ' . $harga . ',-';
        }
        $device = $limit > 1 ? $paket->duration . ' Devices' : $paket->duration . ' Device';
        $durasi = $paket->duration > 1 ? $paket->duration . ' Days' : $paket->duration . ' Day';
        $name = $paket->name == null ? $durasi : $paket->name;
        return response()->json([
            'status' => true,
            "data" => "<div class='overflow-hidden rounded-lg px-4 pt-5 pb-4 shadow-2xl shadow-white/20 transition-all translate-y-0 sm:scale-100 flex flex-col gap-4 undefined opacity-100 scale-100' id='headlessui-dialog-panel-:r8:' data-headlessui-state='open' style='background: var(--cStoreBody); color: rgb(230, 230, 230);'>
    <div class='sm:flex sm:items-start'>
        <div class='mt-3 w-full text-center sm:mt-0 sm:ml-4 sm:text-left'>
            <h3 class='text-lg font-bold leading-6' id='headlessui-dialog-title-:r9:' data-headlessui-state='open'>Detail Pesanan</h3>
            <div class='my-3'>
                <p class='text-sm'>Jika Data Pesanan Kamu Sudah Benar Klik <strong>Beli Sekarang</strong></p>
            </div>
            <div class='mt-6 space-y-2'>
                <div class='flex items-center gap-2'>
                    <div class='w-4 border-t-2' style='border-color: rgb(216,216,0);'></div>
                    <h4 class='shrink-0 pr-4 text-sm font-semibold'>Data User</h4>
                </div>
                <div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm uppercase'>Name</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold' id='nick'>{$request->data}</h4>
                </div>
            </div>
            <div class='mt-6 space-y-2'>
                <div class='flex items-center gap-2'>
                    <div class='w-4 border-t-2' style='border-color: rgb(216,216,0);'></div>
                    <h4 class='shrink-0 pr-4 text-sm font-semibold'>Ringkasan Pesanan</h4>
                </div>
				<div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Provider</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold'>{$product->name}</h4>
                </div>
				<div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Denom</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold'>{$name}</h4>
                </div>
				<div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Game</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold'>{$product->sub_nama}</h4>
                </div>
				<div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Devices</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold'>{$device}</h4>
                </div>
                <div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Channel Pembayaran</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold'>{$payment->category} {$payment->name}</h4>
                </div>
                <div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>Harga</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-extrabold'>{$text}</h4>
                </div>
            </div>
            <div class='mt-6 space-y-2'>
                <div class='flex items-center gap-2'>
                    <div class='w-4 border-t-2' style='border-color: rgb(216,216,0);'></div>
                    <h4 class='shrink-0 pr-4 text-sm font-semibold'>Contact Person</h4>
                </div>
                <div class='flex justify-between'>
                    <h4 class='shrink-0 pr-4 text-sm'>No. WhatsApp</h4>
                    <h4 class='shrink-0 pr-4 text-sm font-bold' id='nomor'>{$request->nomor}</h4>
                </div>
                
                <div class='my-3'>
                <p class='text-sm text-warning'><strong>Informasi : Jika ada kendala</strong></p>
                <p class='text-sm text-warning'><strong>Informasi : Silahkan hubungi Admin</strong></p>
                </div>
                
            </div>
            
        </div>
    </div>
</div>"
        ]);
    }
    public function Pembelian(Request $request)
    {
        $paket = Paket::find($request->service);
        $payment = Payment::where('code', $request->pembayaran)->first();
        $voucher = Voucher::where('code', $request->voucher)->first();
        $invoice_id = 'ORD-' . $this->random(12) . 'INV';
        if ($request->mode == 'paket') {
            $paket = $paket;
        } else {
            $paket = Stok::find($request->service);
        }
        if ($paket) {
            $product = Product::find($paket->product_id);
            $durasi = $paket->duration > 1 ? $paket->duration . ' Days' : $paket->duration . ' Day';
            $name = $paket->name == null ? $durasi : $paket->name;
            if ($request->data == null) {
                return response()->json([
                    'status' => false,
                    'data' => 'Nama tidak boleh kosong',
                ]);
            }
            if ($product) {
                if ($payment) {
                    $total_harga = '';
                    $vc = '';
                    if (isset($request->limit)) {
                        $limit = $request->limit;
                    } else {
                        $limit = 1;
                    }
                    if ($voucher) {
                        if ($voucher->status != 'active') {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah tidak aktif',
                            ]);
                        } elseif ($voucher->stock == 0 or $voucher->stock < 1) {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah habis',
                            ]);
                        } elseif ($voucher->expired_at < date('Y-m-d H:i:s')) {
                            return response()->json([
                                'status' => false,
                                'data' => 'Voucher sudah kadaluarsa',
                            ]);
                        } elseif ($request->data == null) {
                            return response()->json([
                                'status' => false,
                                'data' => 'Nama tidak boleh kosong',
                            ]);
                        } else {
                            if (isset($request->limit)) {
                                $limit = $request->limit;
                            } else {
                                $limit = 1;
                            }

                            if (
                                $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
                                $paket->start_flash_sale < date('Y-m-d H:i:s') &&
                                $paket->end_flash_sale > date('Y-m-d H:i:s')
                            ) {
                                $harga = $paket->flash_sale;
                            } elseif ($paket->diskon != 0) {
                                $harga = $paket->diskon;
                            } else {
                                $harga = $paket->harga ?? $paket->price;
                            }
                            $payment->name = strtoupper($payment->name);
                            if ($paket->harga) {
                                $paket->harga = $harga * $limit;
                            } else {
                                $paket->harga = $paket->price * $limit;
                            }
                            $paket->harga = $paket->harga * $limit;
                            if ($payment->type_fee == 'fix') {
                                $hrga = $paket->harga + $payment->fee;
                            } else {
                                $hrga = ($paket->harga * $payment->fee) / 100;
                            }
                            if ($voucher->type_disc == 'fix') {
                                $total = ($hrga - $voucher->nominal);
                            } else {
                                $total = ($hrga - (($hrga * $voucher->nominal) / 100));
                            }
                            $voucher->stock = $voucher->stock - 1;
                            $vc .= $voucher->code;
                            $total_harga .= $total;
                        }
                    } else {
                        $payment->name = strtoupper($payment->name);

                        if (
                            $paket->switch_flash_sale == 'aktif' && $paket->flash_sale != 0 &&
                            $paket->start_flash_sale < date('Y-m-d H:i:s') &&
                            $paket->end_flash_sale > date('Y-m-d H:i:s')
                        ) {
                            $harga = $paket->flash_sale;
                        } elseif ($paket->diskon != 0) {
                            $harga = $paket->diskon;
                        } else {
                            $harga = $paket->harga ?? $paket->price;
                        }
                        if ($paket->harga) {
                            $paket->harga = $harga * $limit;
                        } else {
                            $paket->harga = $paket->price * $limit;
                        }
                        if ($payment->type_fee == 'fix') {
                            $hrga = $paket->harga + $payment->fee;
                        } else {
                            $hrga = (($paket->harga * $payment->fee) / 100) + $paket->harga;
                        }
                        $total_harga .= $hrga;
                    }
                    $total_harga = $total_harga;
                    
                    // ======== START: BLOK PEMBAYARAN (PAYDISINI / TOKOPAY / FALLBACK) ========

                    if (strtolower($payment->provider) === 'paydisini') {
                        try {
                            $paydisini = new Paydisini();
                            $order = json_decode(
                                $paydisini->create($invoice_id, $payment->code, $total_harga, 3600, $request->ewallet_phone)
                            );

                            if ($order && $order->success == true) {
                                $qr      = $order->data->qrcode_url ?? null;
                                $virtual = $order->data->virtual_account ?? null;
                                $payUrl  = $order->data->checkout_url ?? null;

                                History::create([
                                    'name'            => $request->data,
                                    'invoice_id'      => $invoice_id,
                                    'product_id'      => $product->id,
                                    'product_name'    => $product->name,
                                    'product_subname' => $product->sub_nama,
                                    'paket_id'        => $paket->id,
                                    'paket_name'      => $name,
                                    'duration'        => $paket->duration,
                                    'quantity'        => $limit,
                                    'expired_at'      => Carbon::now()->addHour(1),
                                    'harga'           => $total_harga,
                                    'voucher'         => $vc ?? null,
                                    'number_virtual'  => $virtual,
                                    'qris'            => $qr,
                                    'pay_url'         => $payUrl,
                                    'payment'         => $payment->name,
                                    'status'          => 'pending',
                                ]);

                                return response()->json([
                                    'status'     => true,
                                    'invoice_id' => $invoice_id,
                                ]);
                            }

                            return response()->json([
                                'status' => false,
                                'data'   => 'Gagal membuat orderan',
                            ]);
                        } catch (\Throwable $e) {
                            return response()->json([
                                'status' => false,
                                'data'   => $e->getMessage(),
                            ]);
                        }

                    } elseif (strtolower($payment->provider) === 'tokopay') {
                        try {
                            $tokopay = new Tokopay();
                            $method  = strtoupper($payment->name); // QRIS/BRIVA/DANA/GOPAY/...

                            $res = $tokopay->create($invoice_id, $method, (int) $total_harga);

                            $payUrl  = data_get($res, 'data.pay_url')       ?? data_get($res, 'data.checkout_url');
                            $qr      = data_get($res, 'data.qr_link');      // QRIS
                            $virtual = data_get($res, 'data.va_number')     // VA
                                    ?? data_get($res, 'data.virtual_account');

                            History::create([
                                'name'            => $request->data,
                                'invoice_id'      => $invoice_id,
                                'product_id'      => $product->id,
                                'product_name'    => $product->name,
                                'product_subname' => $product->sub_nama,
                                'paket_id'        => $paket->id,
                                'paket_name'      => $name,
                                'duration'        => $paket->duration,
                                'quantity'        => $limit,
                                'expired_at'      => Carbon::now()->addHour(1),
                                'harga'           => $total_harga,
                                'voucher'         => $vc ?? null,

                                'number_virtual'  => $virtual,
                                'qris'            => $qr,
                                'pay_url'         => $payUrl,
                                'payment'         => $payment->name,
                                'status'          => 'pending',
                            ]);

                            return response()->json([
                                'status'     => true,
                                'invoice_id' => $invoice_id,
                            ]);
                        } catch (\Throwable $e) {
                            return response()->json([
                                'status' => false,
                                'data'   => $e->getMessage(),
                            ]);
                        }

                    } else {
                        // FALLBACK MANUAL (pakai VA statis dari payment)
                        $virtual = $payment->number;

                        History::create([
                            'name'            => $request->data,
                            'invoice_id'      => $invoice_id,
                            'product_id'      => $product->id,
                            'product_name'    => $product->name,
                            'product_subname' => $product->sub_nama,
                            'paket_id'        => $paket->id,
                            'paket_name'      => $name,
                            'duration'        => $paket->duration,
                            'quantity'        => $limit,
                            'expired_at'      => Carbon::now()->addHour(1),
                            'harga'           => $total_harga,
                            'voucher'         => $vc ?? null,

                            'number_virtual'  => $virtual,
                            'qris'            => null,
                            'pay_url'         => null,
                            'payment'         => $payment->name,
                            'status'          => 'pending',
                        ]);

                        return response()->json([
                            'status'     => true,
                            'invoice_id' => $invoice_id,
                        ]);
                    }
                    // ======== END: BLOK PEMBAYARAN ========

                } else {
                    return response()->json([
                        'status' => false,
                        'data' => 'Payment not found',
                    ]);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'data' => 'Product not found',
                ]);
            }
        } else {
            return response()->json([
                'status' => false,
                'data' => 'Paket not found',
            ]);
        }
    } // Fixed: Added missing closing brace for Pembelian method
    
    public function Invoice(History $history)
    {
        $product = Product::find($history->product_id);
        $paket = Paket::where([['id', $history->paket_id], ['name', $history->paket_name]])->first();
        if ($history->expired_at < date('Y-m-d H:i:s') && $history->status == 'pending') {
            $history->status = 'failed';
            $history->save();
        }
        return view('home.invoice', compact('history', 'paket', 'product'));
    }
    public function Download(History $history)
    {
        $link = $history->qris;
        $ext = pathinfo($link, PATHINFO_EXTENSION);

        // Mendapatkan konten gambar dari URL
        $imageContent = file_get_contents($link);

        if ($imageContent !== false) {
            // Mengatur header HTTP untuk mengindikasikan jenis konten
            header('Content-Type: image/' . $ext . '');

            // Mengatur header HTTP untuk menyebutkan bahwa ini adalah attachment yang akan diunduh
            header('Content-Disposition: attachment; filename="' . $history->invoice_id . '.png"');

            // Mengirimkan konten gambar ke client
            return $imageContent;
        } else {
            return redirect()->back()->with('error', 'Gagal mendownload gambar');
        }
    }
    public function FeedbackSend(Request $request)
    {
        $feedback = Feedback::where('invoice_id', $request->invoice_id)->first();
        if ($feedback) {
            return response()->json([
                'status' => false,
                'message' => 'Feedback sudah dikirim',
            ]);
        } else {
            $history = History::where('invoice_id', $request->invoice_id)->first();
            if (!$history) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invoice tidak ditemukan',
                ]);
            }
            Feedback::create([
                'invoice_id' => $request->invoice_id,
                'name' => $request->name,
                'rating' => $request->rating,
                'product_id' => $request->product_id,
                'product_name' => $history->product_name,
                'product_subname' => $history->product_subname,
                'message' => $request->comment,
            ]);
            return response()->json([
                'status' => true,
                'message' => 'Feedback berhasil dikirim',
            ]);
        }
    }

    public function SendWhatsapp(Request $request)
    {
        $invoice = History::where('invoice_id', $request->invoice_id)->first();
        if ($invoice) {
            $config = ConfigWebsite::first();
            if (!$config) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal mengirim invoice!'
                ]);
            } else {
                $product = Product::where('id', $invoice->product_id)->first();
                if ($product) {
                    $produk = $product->name;
                } else {
                    $produk = $invoice->name_product;
                }
                $message = $config->template_whatsapp;
                $message = str_replace('((invoice_id))', $invoice->invoice_id, $message);
                $message = str_replace('((produk))', $produk, $message);
                $message = str_replace('((license))', $invoice->license, $message);
                $message = str_replace('((metode))', strtoupper($invoice->payment), $message);
                $message = str_replace('((price))', 'Rp ' . number_format($invoice->harga, 0, ',', '.'), $message);
                $data = [
                    'number' => $config->wa_gateway,
                    'type' => 'chat',
                    'to' => $request->number,
                    'message' => $message,
                ];
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'http://wa.vip-xnn.com/send',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json'
                    ),
                ));
                $response = curl_exec($curl);
                curl_close($curl);
                if (curl_errno($curl)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Gagal mengirim invoice',
                    ]);
                }
                // berikan jeda 2 detik
                sleep(1);
                $data = [
                    'number' => $config->wa_gateway,
                    'type' => 'chat',
                    'to' => $request->number,
                    'message' => $invoice->license ?? rand(1, 99999),
                ];
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'http://wa.vip-xnn.com/send',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json'
                    ),
                ));
                $response = curl_exec($curl);
                curl_close($curl);
                $decode = json_decode($response);
                if (curl_errno($curl)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Gagal mengirim invoice'
                    ]);
                }
                if ($decode->status == false) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Nomor whatsapp tidak terdaftar'
                    ]);
                } else {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Berhasil mengirim invoice',
                    ]);
                }
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Invoice ID tidak terdaftar'
            ]);
        }
    }
    
    function random($length)
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}