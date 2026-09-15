<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** Where phones connect, and how each scanner account is doing. */
class PhoneStatus
{
    /**
     * @return array{online: array<string, mixed>|null, online_url: string|null, lan_urls: list<string>}
     */
    public function connectInfo(): array
    {
        // Hosted online (cPanel): phones simply open this site's own address.
        if (! config('attendance.admin_localhost_only')) {
            return ['online' => ['status' => 'up', 'hosted' => true], 'online_url' => url('/scan'), 'lan_urls' => []];
        }

        $port = config('attendance.phone_port');

        // Written by tools/gateway.mjs once the Cloudflare tunnel is up.
        $tunnel = @file_get_contents(storage_path('app/tunnel.json'));
        $tunnel = $tunnel ? json_decode($tunnel, true) : null;

        return [
            'online' => $tunnel,
            'online_url' => ! empty($tunnel['url']) ? $tunnel['url'].'/scan' : null,
            'lan_urls' => array_map(fn ($ip) => "https://$ip:$port/scan", $this->lanIps()),
        ];
    }

    /**
     * Each scanner account: scans at this event and when its phone last checked in.
     *
     * @return list<array{id: int, name: string, scans: int, last_scan: string|null, last_seen: string|null}>
     */
    public function phones(Event $event): array
    {
        $counts = Scan::where('event_id', $event->id)->where('status', '!=', 'void')->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as n, max(scanned_at) as last_at')->groupBy('user_id')->get()->keyBy('user_id');

        return User::where('role', User::SCANNER)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'scans' => (int) ($counts[$u->id]->n ?? 0),
                'last_scan' => isset($counts[$u->id]) ? substr($counts[$u->id]->last_at, 11, 5) : null,
                'last_seen' => Cache::get("last_seen:{$u->id}"),
            ])->all();
    }

    /** @return list<string> */
    private function lanIps(): array
    {
        $ips = gethostbynamel(gethostname()) ?: [];
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock && @socket_connect($sock, '8.8.8.8', 53) && @socket_getsockname($sock, $addr)) {
                $ips[] = $addr;   // no packet is sent; this just asks which interface has the default route
            }
            if ($sock) {
                socket_close($sock);
            }
        }
        $ips = array_values(array_unique(array_filter($ips, fn ($ip) => ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '169.254.'))));
        usort($ips, fn ($a, $b) => [! str_starts_with($a, '192.168.'), $a] <=> [! str_starts_with($b, '192.168.'), $b]);

        return $ips;
    }
}
