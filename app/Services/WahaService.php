<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ReminderLog;
use App\Models\WahaGroupConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WahaService
{
    protected string $baseUrl;

    protected string $session;

    protected ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            AppSetting::get('waha_base_url', config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000'))),
            '/'
        );
        $this->session = AppSetting::get('waha_session', config('services.waha.session', env('WAHA_SESSION', 'default')));
        $this->apiKey = AppSetting::get('waha_api_key', config('services.waha.api_key', env('WAHA_API_KEY')));
    }

    /**
     * Build an HTTP client with optional API key authentication.
     *
     * @param  array<string, string>  $extraHeaders
     */
    protected function client(string $baseUrl, ?string $apiKey = null, array $extraHeaders = []): PendingRequest
    {
        $headers = array_merge(['Accept' => 'application/json'], $extraHeaders);

        if ($apiKey) {
            $headers['X-Api-Key'] = $apiKey;
        }

        return Http::baseUrl($baseUrl)->timeout(15)->withHeaders($headers);
    }

    /**
     * Fetch available WhatsApp groups using stored config / env values.
     *
     * @return array{success: bool, groups?: list<array{id: string, name: string}>, error?: string}
     */
    public function getAvailableGroups(): array
    {
        return $this->getAvailableGroupsWithParams($this->baseUrl, $this->session, $this->apiKey);
    }

    /**
     * Fetch available WhatsApp groups using explicit parameters (for live form testing).
     *
     * @return array{success: bool, groups?: list<array{id: string, name: string}>, error?: string}
     */
    public function getAvailableGroupsWithParams(string $baseUrl, ?string $session = 'default', ?string $apiKey = null): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $session = $session ?: 'default';

        // Endpoint patterns to try (different WAHA versions use different routes)
        $endpoints = [
            "/api/{$session}/groups",
            "/api/groups?session={$session}",
            "/api/{$session}/chats",
            "/api/chats?session={$session}",
        ];

        $http = $this->client($baseUrl, $apiKey);

        foreach ($endpoints as $endpoint) {
            try {
                $response = $http->get($endpoint);

                if (! $response->successful()) {
                    continue;
                }

                $data = $response->json();

                // Normalize: some endpoints return the array directly, others wrap it
                $items = is_array($data) && isset($data[0]) ? $data : ($data['data'] ?? $data);

                if (! is_array($items)) {
                    continue;
                }

                $groups = [];
                foreach ($items as $item) {
                    $id = $this->extractGroupId($item);
                    $name = $item['name'] ?? $item['subject'] ?? $item['groupMetadata']['subject'] ?? null;

                    if ($id && str_ends_with($id, '@g.us')) {
                        $groups[] = ['id' => $id, 'name' => $name ?: $id];
                    }
                }

                if (! empty($groups)) {
                    return ['success' => true, 'groups' => $groups];
                }
            } catch (\Exception $e) {
                Log::debug("WAHA endpoint {$endpoint} failed: ".$e->getMessage());

                continue;
            }
        }

        return [
            'success' => false,
            'error' => "Gagal mengambil daftar grup dari {$baseUrl}. Pastikan server WAHA aktif, session \"{$session}\" terhubung, dan API Key benar.",
        ];
    }

    /**
     * Extract a group JID string from various WAHA response formats.
     */
    protected function extractGroupId(array $item): ?string
    {
        if (isset($item['id']) && is_string($item['id'])) {
            return $item['id'];
        }

        if (isset($item['id']['_serialized'])) {
            return $item['id']['_serialized'];
        }

        if (isset($item['chatId']) && is_string($item['chatId'])) {
            return $item['chatId'];
        }

        return null;
    }

    /**
     * Send text message with optional mentions to WAHA.
     */
    public function sendTextMessage(string $chatId, string $text, array $mentions = []): array
    {
        try {
            $payload = [
                'session' => $this->session,
                'chatId' => $chatId,
                'text' => $text,
            ];

            if (! empty($mentions)) {
                $payload['mentions'] = $mentions;
            }

            $response = $this->client($this->baseUrl, $this->apiKey)->post('/api/sendText', $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('WAHA sendText failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('WAHA exception: '.$e->getMessage(), [
                'chatId' => $chatId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get participant JIDs from a WhatsApp group.
     */
    public function getGroupParticipantJids(string $groupJid): array
    {
        try {
            $response = $this->client($this->baseUrl, $this->apiKey)->get("/api/{$this->session}/groups/{$groupJid}");

            if ($response->successful()) {
                $data = $response->json();
                $participants = $data['participants'] ?? [];
                $jids = [];

                foreach ($participants as $p) {
                    if (is_array($p) && isset($p['id'])) {
                        $jids[] = $p['id'];
                    } elseif (is_string($p)) {
                        $jids[] = $p;
                    }
                }

                return $jids;
            }
        } catch (\Exception $e) {
            Log::warning('Could not fetch group participants for '.$groupJid.': '.$e->getMessage());
        }

        return [];
    }

    /**
     * Send message with @everyone tag to all group participants.
     */
    public function sendMentionAllMessage(string $groupJid, string $text): array
    {
        $participants = $this->getGroupParticipantJids($groupJid);

        return $this->sendTextMessage($groupJid, $text, $participants);
    }

    /**
     * Blast message by target group type (ALL_THEORY, B1_PRACTICUM, B2_PRACTICUM).
     */
    public function blastToTargetGroup(string $targetGroup, string $text, string $eventType, int $referenceId): array
    {
        $groupJid = WahaGroupConfig::getJidForTarget($targetGroup);

        if (! $groupJid) {
            return [
                'success' => false,
                'error' => "No group configuration found for {$targetGroup}",
            ];
        }

        $result = $this->sendMentionAllMessage($groupJid, $text);

        // Record log
        ReminderLog::create([
            'event_type' => $eventType,
            'reference_id' => $referenceId,
            'target_group' => $targetGroup,
            'sent_at' => now(),
            'payload_snapshot' => [
                'group_jid' => $groupJid,
                'result' => $result,
                'text_preview' => mb_substr($text, 0, 200),
            ],
        ]);

        return $result;
    }

    /**
     * Template 1: H-15 Menit Pengingat Kelas
     */
    public function formatH15ScheduleReminder($schedule, $override = null): string
    {
        $appDomain = config('app.url', 'sistem-kuliah.test');
        $cleanDomain = preg_replace('#^https?://#', '', $appDomain);

        $subjectName = $schedule->subject->name ?? 'Mata Kuliah';
        $type = ($schedule->subject->type ?? 'THEORY') === 'THEORY' ? 'Teori' : 'Praktikum';
        $startTime = substr($schedule->start_time, 0, 5);
        $endTime = substr($schedule->end_time, 0, 5);
        $room = $schedule->room;
        $lecturer = $schedule->lecturer_name;
        $descNote = $schedule->description ? "\n📝 Info     : {$schedule->description}" : '';

        $overrideNote = '';
        if ($override) {
            if ($override->status === 'ONLINE') {
                $room = 'Daring via Zoom / GMeet';
                $overrideNote = "\n💻 Link Meeting: ".($override->meeting_url ?: '-').
                                ($override->meeting_passcode ? "\n🔑 Passcode: {$override->meeting_passcode}" : '').
                                ($override->reason ? "\n📝 Catatan: {$override->reason}" : '');
            } elseif ($override->new_room) {
                $room = $override->new_room.' (Perubahan Ruang)';
            }
        }

        return "⏰ @everyone [15 MENIT LAGI MULAI]\n\n".
               "📚 Matkul  : {$subjectName} ({$type})\n".
               "⏰ Jam     : {$startTime} - {$endTime} WIB\n".
               "📍 Ruang   : {$room}\n".
               "👨‍🏫 Pengajar: {$lecturer}".
               ($descNote ? "{$descNote}" : '').
               ($overrideNote ? "{$overrideNote}\n" : "\n").
               "\nYuk segera bersiap dan menuju kelas / ruang meeting!\n".
               "🔗 Akses Portal: https://{$cleanDomain}";
    }

    /**
     * Template 2: Darurat Dosen Berhalangan Hadir (Ganti Online)
     */
    public function formatEmergencyOnlineSwitch($schedule, $override): string
    {
        $appDomain = config('app.url', 'sistem-kuliah.test');
        $cleanDomain = preg_replace('#^https?://#', '', $appDomain);

        $subjectName = $schedule->subject->name ?? 'Mata Kuliah';
        $startTime = substr($schedule->start_time, 0, 5);
        $endTime = substr($schedule->end_time, 0, 5);

        return "🌐 @everyone [PERUBAHAN KELAS: DARING / ONLINE]\n\n".
               "📚 Matkul   : {$subjectName}\n".
               "🎯 Target   : {$schedule->target_group}\n".
               "⏰ Jam      : {$startTime} - {$endTime} WIB\n".
               "💻 Media    : Zoom Meeting / Google Meet\n".
               '🔗 Link     : '.($override->meeting_url ?: '-')."\n".
               '🔑 Passcode : '.($override->meeting_passcode ?: '-')."\n".
               '📝 Catatan  : '.($override->reason ?: 'Dosen berhalangan hadir fisik, kuliah dialihkan daring.')."\n\n".
               "Mahasiswa tidak perlu hadir fisik ke ruang kelas.\n".
               "🔗 Cek jadwal lengkap: https://{$cleanDomain}";
    }

    /**
     * Template 3: Reschedule / Kelas Pengganti
     */
    public function formatRescheduleNotice($schedule, $override): string
    {
        $appDomain = config('app.url', 'sistem-kuliah.test');
        $cleanDomain = preg_replace('#^https?://#', '', $appDomain);

        $subjectName = $schedule->subject->name ?? 'Mata Kuliah';
        $statusLabels = [
            'RESCHEDULED' => 'Jadwal Digeser / Pindah Jam',
            'MAKEUP_CLASS' => 'Kelas Pengganti Baru',
            'CANCELLED' => 'Kuliah Diliburkan / Dibatalkan',
            'ONLINE' => 'Dialihkan Daring (Online)',
        ];
        $statusLabel = $statusLabels[$override->status] ?? $override->status;

        $newDate = $override->new_date ? $override->new_date->translatedFormat('l, d F Y') : '-';
        $newTime = ($override->new_start_time && $override->new_end_time)
            ? substr($override->new_start_time, 0, 5).' - '.substr($override->new_end_time, 0, 5).' WIB'
            : '-';
        $newRoom = $override->new_room ?: '-';

        return "⚠️ @everyone [PERUBAHAN JADWAL KULIAH/PRAKTIKUM]\n\n".
               "📚 Matkul     : {$subjectName}\n".
               "🎯 Target     : {$schedule->target_group}\n".
               "📌 Status     : {$statusLabel}\n\n".
               "🗓 Tanggal Baru : {$newDate}\n".
               "⏰ Jam Baru     : {$newTime}\n".
               "📍 Ruang Baru   : {$newRoom}\n".
               '📝 Catatan      : '.($override->reason ?: '-')."\n\n".
               "🔗 Cek kalender lengkap: https://{$cleanDomain}";
    }

    /**
     * Template 4: Pengingat H-1 Deadline Tugas
     */
    public function formatH1TaskReminder($task): string
    {
        $subjectName = $task->subject->name ?? 'Mata Kuliah';
        $deadlineFormatted = $task->deadline ? $task->deadline->translatedFormat('d M Y, H:i') : '-';

        return "📝 @everyone [REMINDER DEADLINE: H-1]\n\n".
               "📚 Matkul   : {$subjectName}\n".
               "📌 Tugas    : {$task->title}\n".
               "⏰ Deadline : {$deadlineFormatted} WIB\n".
               '📁 Format   : '.($task->submission_format ?: 'Sesuai instruksi')."\n".
               '🔗 Submit   : '.($task->submission_url ?: 'Lihat instruksi di portal')."\n\n".
               'Segera selesaikan dan kumpulkan sebelum link ditutup!';
    }
}
