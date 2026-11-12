<?php

namespace Modules\Kiosk\Tests;

use App\Models\User;
use App\Support\Demo\DemoGenerator;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;
use Tests\TestCase;

/**
 * Demo data of the kiosk module (documentation/DEMO.md).
 */
class KioskDemoTest extends TestCase
{
    public function test_the_generator_pairs_two_tablets_that_the_demo_accounts_can_unlock(): void
    {
        Storage::fake('s3');
        app(DemoGenerator::class)->run();

        $this->assertSame(['Lager-Tablet' => KioskPlatform::Android, 'iPad Ausgabe' => KioskPlatform::Ipados], KioskDevice::orderBy('id')->get()->mapWithKeys(fn (KioskDevice $device) => [$device->name => $device->platform])->all());

        $device = KioskDevice::first();
        $member = User::where('email', 'demo2@example.com')->firstOrFail();
        $this->withCookie(KioskDevice::COOKIE_NAME, $device->cookieValue())
            ->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN])
            ->assertRedirect('/kiosk/home');
    }

    public function test_the_same_seed_gives_the_same_pairing_urls(): void
    {
        Storage::fake('s3');
        $tokens = function (): array {
            DB::beginTransaction();
            app(DemoGenerator::class)->run(7);
            $tokens = KioskDevice::orderBy('id')->get()->map(fn (KioskDevice $device) => $device->token)->all();
            DB::rollBack();
            Cache::flush();

            return $tokens;
        };

        $this->assertSame($tokens(), $tokens());
    }
}
