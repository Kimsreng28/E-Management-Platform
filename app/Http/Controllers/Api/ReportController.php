<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use League\Csv\Writer;
use SplTempFileObject;
use Carbon\Carbon;

class ReportController extends Controller
{
    // Helper method to calculate date ranges
    private function getDateRange($period, $customRange = null)
    {
        $today = Carbon::now();

        if ($customRange && isset($customRange['start']) && isset($customRange['end'])) {
            return [
                'start' => Carbon::parse($customRange['start'])->startOfDay(),
                'end' => Carbon::parse($customRange['end'])->endOfDay()
            ];
        }

        switch ($period) {
            case 'week':
                $start = $today->copy()->startOfWeek();
                $end = $today->copy()->endOfWeek();
                break;
            case 'month':
                $start = $today->copy()->startOfMonth();
                $end = $today->copy()->endOfMonth();
                break;
            case 'year':
                $start = $today->copy()->startOfYear();
                $end = $today->copy()->endOfYear();
                break;
            case 'last_week':
                $start = $today->copy()->subWeek()->startOfWeek();
                $end = $today->copy()->subWeek()->endOfWeek();
                break;
            case 'last_month':
                $start = $today->copy()->subMonth()->startOfMonth();
                $end = $today->copy()->subMonth()->endOfMonth();
                break;
            case 'last_year':
                $start = $today->copy()->subYear()->startOfYear();
                $end = $today->copy()->subYear()->endOfYear();
                break;
            case 'day':
            default:
                $start = $today->copy()->startOfDay();
                $end = $today->copy()->endOfDay();
                break;
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    // Sales Overview (stats for dashboard)
    public function salesOverview(Request $request)
    {
        $period = $request->get('period', 'month');
        $customRange = $request->get('custom_range');

        $dateRange = $this->getDateRange($period, $customRange);
        $start = $dateRange['start'];
        $end = $dateRange['end'];

        $totalRevenue = Order::whereBetween('created_at', [$start, $end])->sum('total');
        $totalOrders = Order::whereBetween('created_at', [$start, $end])->count();
        $newCustomers = User::whereBetween('created_at', [$start, $end])->count();
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        // Calculate previous period for comparison
        $daysDiff = $end->diffInDays($start);
        $prevStart = $start->copy()->subDays($daysDiff + 1);
        $prevEnd = $start->copy()->subDay();

        $prevRevenue = Order::whereBetween('created_at', [$prevStart, $prevEnd])->sum('total');
        $prevOrders = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $prevCustomers = User::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $prevAvgOrderValue = $prevOrders > 0 ? $prevRevenue / $prevOrders : 0;

        return response()->json([
            'totalRevenue' => $totalRevenue,
            'revenueChange' => $prevRevenue != 0 ? round(($totalRevenue - $prevRevenue)/$prevRevenue*100, 2) : ($totalRevenue > 0 ? 100 : 0),
            'totalOrders' => $totalOrders,
            'ordersChange' => $prevOrders != 0 ? round(($totalOrders - $prevOrders)/$prevOrders*100, 2) : ($totalOrders > 0 ? 100 : 0),
            'newCustomers' => $newCustomers,
            'customersChange' => $prevCustomers != 0 ? round(($newCustomers - $prevCustomers)/$prevCustomers*100, 2) : ($newCustomers > 0 ? 100 : 0),
            'avgOrderValue' => round($avgOrderValue, 2),
            'avgOrderValueChange' => $prevAvgOrderValue != 0 ? round(($avgOrderValue - $prevAvgOrderValue)/$prevAvgOrderValue*100, 2) : ($avgOrderValue > 0 ? 100 : 0),
        ]);
    }

    // Sales Report
    public function salesReport(Request $request)
    {
        $period = $request->get('period', 'month');
        $customRange = $request->get('custom_range');

        $dateRange = $this->getDateRange($period, $customRange);
        $start = $dateRange['start'];
        $end = $dateRange['end'];

        $salesData = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total) as revenue'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('COUNT(DISTINCT user_id) as new_customers')
            )
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Calculate growth percentage for each day
        $previousRevenue = 0;
        $salesDataWithGrowth = $salesData->map(function ($item) use (&$previousRevenue) {
            $growth = $previousRevenue > 0 ? (($item->revenue - $previousRevenue) / $previousRevenue) * 100 : 0;
            $previousRevenue = $item->revenue;

            return [
                'period' => $item->date,
                'revenue' => $item->revenue,
                'orders' => $item->orders,
                'new_customers' => $item->new_customers,
                'growth' => round($growth, 2)
            ];
        });

        return response()->json($salesDataWithGrowth);
    }

    // Product Performance
    public function productPerformance(Request $request)
    {
        try {
            $period = $request->get('period', 'month');
            $customRange = $request->get('custom_range');
            $dateRange = $this->getDateRange($period, $customRange);
            $start = $dateRange['start'];
            $end = $dateRange['end'];

            $products = Product::with('category')
                ->withCount(['orderItems as units_sold' => function($q) use ($start, $end) {
                    $q->whereHas('order', fn($oq) => $oq->whereBetween('created_at', [$start, $end]));
                }])
                ->withSum(['orderItems as revenue' => function($q) use ($start, $end) {
                    $q->whereHas('order', fn($oq) => $oq->whereBetween('created_at', [$start, $end]));
                }], DB::raw('order_items.quantity * products.price'))
                ->get()
                ->filter(fn($p) => $p->units_sold > 0);

            $totalRevenue = $products->sum('revenue');

            $productPerformance = $products->map(fn($product) => [
                'product' => $product->name,
                'category' => $product->category?->name ?? 'N/A',
                'units_sold' => $product->units_sold,
                'revenue' => $product->revenue,
                'performance' => $totalRevenue > 0 ? round(($product->revenue / $totalRevenue) * 100, 2) : 0
            ]);

            return response()->json($productPerformance);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Inventory Report
    public function inventoryReport(Request $request)
    {
        $lowStockThreshold = $request->get('threshold', 10);

        $inventory = Product::all()->map(function($p) use ($lowStockThreshold) {
            $status = 'In Stock';
            $action = 'None';

            if ($p->stock <= 0) {
                $status = 'Out of Stock';
                $action = 'Restock Immediately';
            } elseif ($p->stock <= $lowStockThreshold) {
                $status = 'Low Stock';
                $action = 'Consider Restocking';
            }

            return [
                'product' => $p->name,
                'current_stock' => $p->stock,
                'threshold' => $lowStockThreshold,
                'status' => $status,
                'action_needed' => $action
            ];
        });

        return response()->json($inventory);
    }

    // Customer Analysis
    public function customerAnalysis(Request $request)
    {
        try {
            $period = $request->get('period', 'month');
            $customRange = $request->get('custom_range');
            $dateRange = $this->getDateRange($period, $customRange);
            $start = $dateRange['start'];
            $end = $dateRange['end'];

            $customers = User::withCount(['orders as total_orders' => fn($q) => $q->whereBetween('created_at', [$start, $end])])
                ->withSum(['orders as total_revenue' => fn($q) => $q->whereBetween('created_at', [$start, $end])], 'total')
                ->with(['orders' => fn($q) => $q->whereBetween('created_at', [$start, $end])->orderBy('created_at', 'desc')->limit(1)])
                ->get()
                ->filter(fn($c) => $c->total_orders > 0);

            $avgOrderValue = $customers->avg('total_revenue') ?? 0;

            $customerAnalysis = $customers->map(fn($customer) => [
                'customer' => $customer->name,
                'total_orders' => $customer->total_orders,
                'total_revenue' => $customer->total_revenue,
                'last_order' => $customer->orders->first()?->created_at?->format('Y-m-d') ?? 'N/A',
                'customer_value' => $customer->total_revenue > $avgOrderValue * 2 ? 'High Value' :
                                   ($customer->total_revenue > $avgOrderValue ? 'Medium Value' : 'Standard')
            ]);

            return response()->json($customerAnalysis);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Export Reports
    public function exportReport(Request $request, $type)
    {
        try {
            $reportType = $request->get('report_type');
            $data = [];
            $filename = '';
            $headers = [];

            switch ($reportType) {
                case 'sales':
                    $data = $this->salesReport($request)->getOriginalContent();
                    $filename = 'sales_report';
                    $headers = ['Period', 'Revenue', 'Orders', 'New Customers', 'Growth (%)'];
                    break;
                case 'products':
                    $data = $this->productPerformance($request)->getOriginalContent();
                    $filename = 'product_performance';
                    $headers = ['Product', 'Category', 'Units Sold', 'Revenue', 'Performance (%)'];
                    break;
                case 'inventory':
                    $data = $this->inventoryReport($request)->getOriginalContent();
                    $filename = 'inventory_report';
                    $headers = ['Product', 'Current Stock', 'Threshold', 'Status', 'Action Needed'];
                    break;
                case 'customers':
                    $data = $this->customerAnalysis($request)->getOriginalContent();
                    $filename = 'customer_analysis';
                    $headers = ['Customer', 'Total Orders', 'Total Revenue', 'Last Order', 'Customer Value'];
                    break;
                default:
                    return response()->json(['error' => 'Invalid report type'], 400);
            }

            if ($type === 'csv') {
                $csv = Writer::createFromFileObject(new SplTempFileObject());
                $csv->insertOne($headers);
                foreach ($data as $row) {
                    $csv->insertOne(array_values((array)$row));
                }
                return response((string)$csv, 200, [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
                ]);
            } elseif ($type === 'pdf') {
                $pdf = Pdf::loadView('reports.pdf', [
                    'data' => $data,
                    'headers' => $headers,
                    'title' => ucfirst(str_replace('_', ' ', $filename)),
                ]);
                return $pdf->download($filename.'.pdf');
            }

            return response()->json(['error' => 'Invalid export type'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
