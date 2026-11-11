<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Document;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductLine;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Registration;
use App\Models\User;
use App\Support\Plan;
use App\Support\Scanning\ScanResult;
use App\Support\Uploads;
use App\Tenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local demo data - two manufacturers and a dealer that carries both.
 * Every account's password is "dealerdock-demo". Two-factor isn't set up, so
 * the first sign-in walks through it.
 *
 * Deliberately not WithoutModelEvents: tenant-owned rows get their
 * manufacturer from the model events.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Manufacturer::create(['name' => 'Acme Scrapers', 'slug' => 'acme', 'plan' => Plan::PRO]);
        $bolt = Manufacturer::create(['name' => 'Bolt Attachments', 'slug' => 'bolt', 'plan' => Plan::STARTER]);

        $this->member('owner@acme.test', 'Avery Owner', Membership::MANUFACTURER, $acme->id, 'owner');
        $this->member('rep@acme.test', 'Riley Rep', Membership::MANUFACTURER, $acme->id, 'sales_rep');
        $this->member('owner@bolt.test', 'Blake Owner', Membership::MANUFACTURER, $bolt->id, 'owner');

        Tenant::run($acme, function () {
            foreach (['Scrapers', 'Rippers', 'Hitches'] as $name) {
                ProductLine::create(['name' => $name]);
            }
        });
        Tenant::run($bolt, fn () => ProductLine::create(['name' => 'Buckets']));

        $prairie = Dealer::create(['name' => 'Prairie Equipment', 'city' => 'Brandon', 'region' => 'MB', 'country' => 'CA']);
        $northern = Dealer::create(['name' => 'Northern Ag Supply', 'city' => 'Fargo', 'region' => 'ND', 'country' => 'US']);

        [$acmePrairie, $acmeNorthern] = Tenant::run($acme, fn () => [
            DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'PRA001']),
            DealerLink::create(['dealer_id' => $northern->id, 'dealer_code' => 'NOR001']),
        ]);
        $boltPrairie = Tenant::run($bolt, fn () => DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'B-1042']));

        // Carries both brands - one login, a brand switcher.
        $this->member('dealer@prairie.test', 'Pat Dealer', Membership::DEALER, $prairie->id, 'dealer_admin');

        $this->machines($acme, $bolt, $acmePrairie, $acmeNorthern, $boltPrairie);

        // Acme is fully set up (logo and all), so its checklist is gone; Bolt
        // is part way, so its owner sees "Getting started" on the dashboard.
        Tenant::run($acme, fn () => $this->logo($acme));
    }

    /** A plain wordmark PNG, drawn here so the demo needs no image files. */
    private function logo(Manufacturer $manufacturer): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return; // no GD: the demo just goes without a logo
        }

        $image = imagecreatetruecolor(240, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 60, 60, imagecolorallocate($image, 200, 70, 30));
        imagestring($image, 5, 76, 22, strtoupper($manufacturer->name), imagecolorallocate($image, 30, 30, 30));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $path = $manufacturer->id.'/branding/'.Str::uuid().'.png';
        Uploads::disk()->put($path, $png);
        $manufacturer->forceFill(['logo_path' => $path, 'logo_mime' => 'image/png', 'logo_updated_at' => now()])->save();
    }

    /** Models and machines: some at each dealer, some still at the factory, and a transfer in the history. */
    private function machines(Manufacturer $acme, Manufacturer $bolt, DealerLink $acmePrairie, DealerLink $acmeNorthern, DealerLink $boltPrairie): void
    {
        $owner = User::where('email', 'owner@acme.test')->first();

        Tenant::run($acme, function () use ($owner, $acmePrairie, $acmeNorthern) {
            $lines = ProductLine::pluck('id', 'name');
            $s400 = ProductModel::create(['product_line_id' => $lines['Scrapers'], 'name' => 'S-400', 'category' => 'Pull-type scraper']);
            $s600 = ProductModel::create(['product_line_id' => $lines['Scrapers'], 'name' => 'S-600', 'category' => 'Pull-type scraper']);
            $r200 = ProductModel::create(['product_line_id' => $lines['Rippers'], 'name' => 'R-200', 'category' => 'Ripper']);
            foreach (['Hydraulic tilt', 'Laser-ready mast', 'Wide tires'] as $option) {
                ProductOption::create(['product_model_id' => $s400->id, 'name' => $option]);
            }

            $plan = [
                // serial, model, dealer, status
                ['AS4-24001', $s400, $acmePrairie, 'inventory'],
                ['AS4-24002', $s400, $acmePrairie, 'demo'],
                ['AS4-24003', $s400, $acmeNorthern, 'sold'],
                ['AS6-24001', $s600, $acmeNorthern, 'rental'],
                ['AS6-24002', $s600, null, 'inventory'],
                ['AR2-24001', $r200, $acmePrairie, 'used'],
                ['AR2-24002', $r200, null, 'inventory'],
            ];

            foreach ($plan as $i => [$serial, $model, $link, $status]) {
                $machine = Machine::add(
                    ['product_model_id' => $model->id, 'serial_no' => $serial, 'production_no' => 'PO-'.(5100 + $i), 'built_at' => now()->subWeeks(10 - $i)],
                    $link,
                    $owner,
                );
                $machine->changeStatus($status, $owner);
            }

            // Went to Northern first, then moved to Prairie.
            $moved = Machine::add(['product_model_id' => $s600->id, 'serial_no' => 'AS6-24003', 'production_no' => 'PO-5200'], $acmeNorthern, $owner);
            $moved->transferTo($acmePrairie, $owner, 'Prairie had a buyer lined up.');

            $this->registrations($owner, $moved);
            $this->library($owner, ['S-400' => $s400, 'S-600' => $s600, 'R-200' => $r200]);
            $this->priceLists(['S-400' => $s400, 'S-600' => $s600, 'R-200' => $r200], $acmePrairie);
        });

        Tenant::run($bolt, function () use ($boltPrairie) {
            $line = ProductLine::where('name', 'Buckets')->first();
            $model = ProductModel::create(['product_line_id' => $line->id, 'name' => 'B-72 Bucket']);
            Machine::add(['product_model_id' => $model->id, 'serial_no' => 'BB72-0001'], $boltPrairie, null);

            $list = PriceList::create(['name' => 'Dealer prices', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => PriceListItem::MODEL, 'item_id' => $model->id, 'price_cents' => 320000]);
        });
    }

    /**
     * Registrations at Acme: an older sale already approved, and the machine
     * Prairie had a buyer for, waiting in the review queue with its bill of
     * sale. Runs inside Tenant::run(Acme).
     */
    private function registrations(User $owner, Machine $waiting): void
    {
        $dealer = User::where('email', 'dealer@prairie.test')->first();
        $rental = Machine::where('serial_no', 'AS6-24001')->first();

        // Northern's rental, approved: the machine was already marked rental above.
        $approved = Registration::create([
            'machine_id' => $rental->id, 'dealer_link_id' => $rental->dealer_link_id, 'type' => 'rental',
            'delivered_on' => now()->subWeeks(3), 'submitted_by' => null,
            'customer' => ['company' => 'Red River Earthworks', 'contact_name' => 'Sam Ortiz', 'email' => 'sam@redriver.example', 'city' => 'Fargo', 'region' => 'ND', 'country' => 'US'],
        ]);
        $approved->approve($owner);

        $pending = Registration::create([
            'machine_id' => $waiting->id, 'dealer_link_id' => $waiting->dealer_link_id, 'type' => 'sale',
            'delivered_on' => now()->subDays(2), 'submitted_by' => $dealer->id,
            'customer' => ['company' => 'Hill Farms', 'contact_name' => 'Dana Hill', 'email' => 'dana@hillfarms.example', 'phone' => '204-555-0142', 'city' => 'Brandon', 'region' => 'MB', 'country' => 'CA'],
            'notes' => 'Delivered with the wide tire option fitted at our shop.',
        ]);
        // A tiny real PDF, so the download works in the demo. Seeded, so not scanned.
        $path = Tenant::current()->id().'/registrations/'.$pending->id.'/'.Str::uuid().'.pdf';
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
        Uploads::disk()->put($path, $pdf);
        $pending->documents()->create([
            'kind' => 'bill_of_sale', 'original_name' => 'Hill Farms bill of sale.pdf', 'path' => $path, 'mime' => 'application/pdf',
            'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf), 'scan_status' => ScanResult::NOT_SCANNED, 'uploaded_by' => $dealer->id,
        ]);
    }

    /**
     * Acme's prices: a US list (the default) and a Canadian one in CAD, which
     * Prairie (in Manitoba) buys from. One option is left unpriced on the CAD
     * list - it shows as "price on request". Runs inside Tenant::run(Acme).
     *
     * @param  array<string, ProductModel>  $models
     */
    private function priceLists(array $models, DealerLink $canadian): void
    {
        $us = PriceList::create(['name' => 'US dealers', 'currency' => 'USD', 'notes' => '2027 model year.']);
        $us->forceFill(['is_default' => true])->save();
        $ca = PriceList::create(['name' => 'Canadian dealers', 'currency' => 'CAD', 'notes' => 'Prices in Canadian dollars.']);

        // model => [USD, CAD]; options of the S-400 => [USD, CAD|null]
        $prices = ['S-400' => [48500, 66000], 'S-600' => [63900, 87000], 'R-200' => [18750, 25500]];
        $options = ['Hydraulic tilt' => [2150, 2950], 'Laser-ready mast' => [3400, 4650], 'Wide tires' => [1275, null]];

        foreach ($prices as $model => [$usd, $cad]) {
            $us->items()->create(['item_type' => PriceListItem::MODEL, 'item_id' => $models[$model]->id, 'price_cents' => $usd * 100]);
            $ca->items()->create(['item_type' => PriceListItem::MODEL, 'item_id' => $models[$model]->id, 'price_cents' => $cad * 100]);
        }
        foreach ($models['S-400']->options as $option) {
            [$usd, $cad] = $options[$option->name];
            $us->items()->create(['item_type' => PriceListItem::OPTION, 'item_id' => $option->id, 'price_cents' => $usd * 100]);
            if ($cad !== null) {
                $ca->items()->create(['item_type' => PriceListItem::OPTION, 'item_id' => $option->id, 'price_cents' => $cad * 100]);
            }
        }

        $canadian->forceFill(['price_list_id' => $ca->id])->save();
    }

    /**
     * Acme's library: manuals by model and serial range, a bulletin across two
     * models, a policy that covers everything, and one staff-only document.
     * Tiny real PDFs, so opening them works in the demo. Runs inside
     * Tenant::run(Acme).
     *
     * @param  array<string, ProductModel>  $models
     */
    private function library(User $owner, array $models): void
    {
        $documents = [
            // title, kind, audience, [model => [from, to]]
            ['S-400 operator manual (2024 and earlier)', 'manual', Document::EVERYONE, ['S-400' => [null, 'AS4-24000']]],
            ['S-400 operator manual', 'manual', Document::EVERYONE, ['S-400' => ['AS4-24001', null]]],
            ['S-400 parts book', 'parts_book', Document::EVERYONE, ['S-400' => [null, null]]],
            ['S-600 operator manual', 'manual', Document::EVERYONE, ['S-600' => [null, null]]],
            ['R-200 operator manual', 'manual', Document::EVERYONE, ['R-200' => [null, null]]],
            ['Bulletin SB-112: check hitch pin torque', 'bulletin', Document::EVERYONE, ['S-400' => ['AS4-24001', 'AS4-24050'], 'S-600' => [null, null]]],
            ['Limited warranty policy', 'other', Document::EVERYONE, []],
            ['Warranty labour rates (internal)', 'other', Document::STAFF, []],
        ];

        foreach ($documents as [$title, $kind, $audience, $covers]) {
            $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
            $path = Tenant::current()->id().'/library/'.Str::uuid().'.pdf';
            Uploads::disk()->put($path, $pdf);

            $document = Document::create([
                'title' => $title, 'kind' => $kind, 'audience' => $audience,
                'original_name' => Str::slug($title).'.pdf', 'path' => $path, 'mime' => 'application/pdf',
                'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf), 'scan_status' => ScanResult::NOT_SCANNED,
                'uploaded_by' => $owner->id,
            ]);
            foreach ($covers as $model => [$from, $to]) {
                $document->models()->create(['product_model_id' => $models[$model]->id, 'serial_from' => $from, 'serial_to' => $to]);
            }
        }
    }

    private function member(string $email, string $name, string $orgType, int $orgId, string $role): void
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'dealerdock-demo']);
        Membership::create(['user_id' => $user->id, 'org_type' => $orgType, 'org_id' => $orgId, 'role' => $role]);
    }
}
