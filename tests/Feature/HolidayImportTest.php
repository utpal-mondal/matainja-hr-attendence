<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HolidayImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 06:00:00');
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('holidays.csv', $content);
    }

    public function test_holidays_page_offers_the_import_and_a_template(): void
    {
        $this->get('/admin/organisation/holidays')->assertOk()
            ->assertSee('Import holidays from CSV')
            ->assertSee(route('admin.holidays.import.template'), false);

        // Other organisation screens don't get it.
        $this->get('/admin/organisation/departments')->assertOk()->assertDontSee('Import holidays from CSV');

        $template = $this->get(route('admin.holidays.import.template'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("Date,Name,Type,Branch\n", $template);
        $this->assertStringContainsString('26/01/2027,"Republic Day",Public,', $template);
    }

    public function test_valid_rows_are_added_and_bad_ones_reported(): void
    {
        $kolkata = Branch::create(['name' => 'Kolkata Office', 'code' => 'KOL', 'timezone' => 'Asia/Kolkata']);
        Holiday::create(['holiday_date' => '2027-01-01', 'name' => 'New Year', 'type' => 'company']);

        $file = $this->csv("\xEF\xBB\xBFHoliday Date,Holiday Name,Type,Branch\n"
            ."26/01/2027,Republic Day,Public,\n"
            ."2027-03-04,Holi,restricted,All branches\n"
            ."15th August 2027,Independence Day,,\n"
            ."16.10.2027,Durga Puja,Company,KOL\n"
            ."17/10/2027,Durga Puja Dashami,company,kolkata office\n"
            ."01/01/2027,New Year again,Company,\n"     // already listed
            ."31/02/2027,Bad date,Public,\n"           // not a real date
            .",No date,Public,\n"
            ."02/10/2027,,Public,\n"                   // no name
            ."25/12/2027,Christmas,Bank,\n"            // unknown type
            ."14/11/2027,Diwali,Public,Mumbai\n"       // unknown branch
            ."26/01/2027,Republic Day duplicate,Public,\n" // twice in the file
            ."\n");

        $this->post(route('admin.holidays.import'), ['file' => $file])
            ->assertRedirect(route('admin.org.index', 'holidays'))
            ->assertSessionHas('success', '5 holidays added. 7 rows were skipped (see below).');

        $this->assertSame(6, Holiday::count());
        $this->assertDatabaseHas('holidays', ['name' => 'Republic Day', 'type' => 'public', 'branch_id' => null]);
        $this->assertDatabaseHas('holidays', ['name' => 'Holi', 'type' => 'optional', 'branch_id' => null]);
        $this->assertDatabaseHas('holidays', ['name' => 'Independence Day', 'type' => 'public']);
        $this->assertDatabaseHas('holidays', ['name' => 'Durga Puja', 'type' => 'company', 'branch_id' => $kolkata->id]);
        $this->assertDatabaseHas('holidays', ['name' => 'Durga Puja Dashami', 'branch_id' => $kolkata->id]);
        $this->assertSame('2027-08-15', Holiday::where('name', 'Independence Day')->sole()->holiday_date->toDateString());

        $skipped = collect(session('holiday_import_skipped'))->pluck('reason', 'line');
        $this->assertStringContainsString('already listed on 01 Jan 2027', $skipped[7]);
        $this->assertStringContainsString('"31/02/2027" is not a date', $skipped[8]);
        $this->assertSame('The date is missing.', $skipped[9]);
        $this->assertSame('The name is missing.', $skipped[10]);
        $this->assertStringContainsString('Type "Bank"', $skipped[11]);
        $this->assertStringContainsString('no branch called "Mumbai"', $skipped[12]);
        $this->assertStringContainsString('already listed on 26 Jan 2027', $skipped[13]);

        $this->get('/admin/organisation/holidays')->assertSee('These rows were not imported')->assertSee('Line 12 · Diwali');
        $this->assertSame(5, AuditLog::where('action', 'holidays.imported')->sole()->after['created']);
    }

    public function test_a_branch_holiday_can_share_a_date_with_another_branch(): void
    {
        Branch::create(['name' => 'Kolkata', 'code' => 'KOL']);
        Branch::create(['name' => 'Delhi', 'code' => 'DEL']);

        $this->post(route('admin.holidays.import'), ['file' => $this->csv("Date,Name,Branch\n16/10/2027,Durga Puja,KOL\n16/10/2027,Office day off,DEL\n")])
            ->assertSessionHas('success', '2 holidays added.');
    }

    public function test_files_without_the_needed_columns_or_rows_are_refused(): void
    {
        $this->post(route('admin.holidays.import'), ['file' => $this->csv("Occasion only\nDiwali\n")])
            ->assertSessionHas('error', 'The file needs at least a "Date" and a "Name" column. Download the template to see the expected columns.');

        $this->post(route('admin.holidays.import'), ['file' => $this->csv("Date,Name\n")])
            ->assertSessionHas('error', 'The file has no holiday rows.');

        $this->post(route('admin.holidays.import'), ['file' => UploadedFile::fake()->create('holidays.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Holiday::count());
    }

    public function test_only_super_admins_can_import(): void
    {
        $user = User::factory()->create();
        $user->assignRole('HR Admin');

        $this->actingAs($user)->post(route('admin.holidays.import'), ['file' => $this->csv("Date,Name\n26/01/2027,Republic Day\n")])
            ->assertRedirect(route('login'));
        $this->assertSame(0, Holiday::count());
    }
}
