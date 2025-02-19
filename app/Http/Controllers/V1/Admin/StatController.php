<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerVless;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getOverride(Request $request)
    {
        return [
            'data' => [
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::with(['parent'])->get()->toArray(),
            'v2ray' => ServerVmess::with(['parent'])->get()->toArray(),
            'trojan' => ServerTrojan::with(['parent'])->get()->toArray(),
            'vmess' => ServerVmess::with(['parent'])->get()->toArray(),
            'hysteria' => ServerHysteria::with(['parent'])->get()->toArray(),
            'vless' => ServerVless::with(['parent'])->get()->toArray(),
        ];

        $recordAt = strtotime(date('Y-m-d'));
        $statService = new StatisticalService();
        $statService->setStartAt($recordAt);
        $stats = $statService->getStatServer();
        $statistics = collect($stats)->map(function ($item){
            $item['total'] = $item['u'] + $item['d'];
            return $item;
        })->sortByDesc('total')->values()->all();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                    if($server['parent']) $statistics[$k]['server_name'] .= "({$server['parent']['name']})";
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => collect($statistics)->take(15)->all()
        ];
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::with(['parent'])->get()->toArray(),
            'v2ray' => ServerVmess::with(['parent'])->get()->toArray(),
            'trojan' => ServerTrojan::with(['parent'])->get()->toArray(),
            'vmess' => ServerVmess::with(['parent'])->get()->toArray(),
            'hysteria' => ServerHysteria::with(['parent'])->get()->toArray(),
            'vless' => ServerVless::with(['parent'])->get()->toArray(),
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                    if($server['parent']) $statistics[$k]['server_name'] .= "({$server['parent']['name']})";
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $request->input('user_id'));

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();

        // 追加当天流量
        $recordAt = strtotime(date('Y-m-d'));
        $statService = new StatisticalService();
        $statService->setStartAt($recordAt);
        $todayTraffics = $statService->getStatUserByUserID($request->input('user_id'));
        if (($current == 1)  && count($todayTraffics) > 0) {
            foreach ($todayTraffics as $todayTraffic){
                $todayTraffic['server_rate'] = number_format($todayTraffic['server_rate'], 2);
                $records->prepend($todayTraffic);
            } 
        };
        
        return [
            'data' => $records,
            'total' => $total + count($todayTraffics),
        ];
    }

}

