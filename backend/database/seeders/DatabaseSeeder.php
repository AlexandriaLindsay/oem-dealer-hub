<?php

namespace Database\Seeders;

use App\Models\Broadcast;
use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Document;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\PartsOrder;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductLine;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Quote;
use App\Models\Registration;
use App\Models\SalesOrder;
use App\Models\Territory;
use App\Models\UsageDay;
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

        // A trial about to run out, so the staff console's "trials ending" list has one.
        $cobalt = Manufacturer::create(['name' => 'Cobalt Tillage', 'slug' => 'cobalt', 'plan' => Plan::TRIAL, 'trial_ends_at' => now()->addDays(3)]);
        $this->member('owner@cobalt.test', 'Casey Owner', Membership::MANUFACTURER, $cobalt->id, 'owner');

        // OEM Dealer Hub's own support person: the staff console (/admin), no manufacturer.
        User::create(['name' => 'Sam Staff', 'email' => 'staff@oemdealerhub.test', 'password' => 'dealerdock-demo'])
            ->forceFill(['is_platform_staff' => true])->save();

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

        Tenant::run($acme, fn () => $this->usage($acmePrairie));
        Tenant::run($acme, fn () => $this->territories($acme, $acmePrairie, $acmeNorthern));

        // Prairie sells only Acme's scrapers: Pat's prices and quotes show just
        // that line, though their used R-200 ripper is still in their machines.
        // Northern has none chosen, so it carries every line.
        Tenant::run($acme, fn () => $acmePrairie->productLines()->attach(
            ProductLine::where('name', 'Scrapers')->value('id'), ['manufacturer_id' => $acme->id],
        ));
    }

    /**
     * Acme's territories: Canada (Prairie), covered by Riley the sales rep,
     * and United States (Northern), not covered yet. So rep@acme.test sees
     * only Prairie - territories at work. Runs inside Tenant::run(Acme).
     */
    private function territories(Manufacturer $acme, DealerLink $prairie, DealerLink $northern): void
    {
        $canada = Territory::create(['name' => 'Canada']);
        $us = Territory::create(['name' => 'United States']);
        $prairie->forceFill(['territory_id' => $canada->id])->save();
        $northern->forceFill(['territory_id' => $us->id])->save();

        $riley = Membership::where('org_type', Membership::MANUFACTURER)->where('org_id', $acme->id)
            ->whereHas('user', fn ($q) => $q->where('email', 'rep@acme.test'))->value('id');
        $canada->reps()->attach($riley, ['manufacturer_id' => $acme->id]);
    }

    /**
     * Two months of Acme usage, so the Usage report has something to show:
     * Pat at Prairie most weekdays, Acme's own staff, and Northern not at
     * all (it has no logins) - the "worth a call" row. Fixed seed, so every
     * reseed looks the same. Runs inside Tenant::run(Acme).
     */
    private function usage(DealerLink $prairie): void
    {
        mt_srand(42);
        $people = [
            // user, dealer link, chance of using it on a weekday, areas they use
            [User::where('email', 'dealer@prairie.test')->value('id'), $prairie->id, 0.7, ['dashboard', 'machines', 'prices', 'quotes', 'parts-orders', 'documents', 'announcements']],
            [User::where('email', 'owner@acme.test')->value('id'), null, 0.8, ['dashboard', 'machines', 'dealers', 'fleet', 'registrations', 'price-lists', 'broadcasts']],
            [User::where('email', 'rep@acme.test')->value('id'), null, 0.5, ['dashboard', 'machines', 'fleet', 'quotes']],
        ];

        $rows = [];
        for ($ago = 60; $ago >= 0; $ago--) {
            $day = now()->subDays($ago);
            foreach ($people as [$user, $link, $chance, $areas]) {
                $odds = $day->isWeekend() ? $chance / 6 : $chance;
                if (mt_rand() / mt_getrandmax() > $odds) {
                    continue;
                }
                // A few areas a day, the first ones (their main jobs) more often.
                foreach (array_slice($areas, 0, mt_rand(1, 4)) as $i => $area) {
                    $rows[] = [
                        'manufacturer_id' => Tenant::current()->id(), 'user_id' => $user, 'dealer_link_id' => $link,
                        'day' => $day->toDateString(), 'area' => $area, 'count' => mt_rand(1, 12 - 2 * $i),
                    ];
                }
            }
        }

        UsageDay::insert($rows);
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
        // And its own colour (Pro), the logo's orange - so the demo shows a brand that isn't OEM Dealer Hub blue.
        $manufacturer->forceFill(['logo_path' => $path, 'logo_mime' => 'image/png', 'logo_updated_at' => now(), 'brand_color' => '#c2410c'])->save();
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

        $this->quotes($canadian, $models, $ca);
        $this->partsOrders($canadian);
        $this->broadcasts($canadian);
    }

    /** Two announcements: one to every dealer, one only to Northern (so Prairie sees just the first). */
    private function broadcasts(DealerLink $prairie): void
    {
        $owner = User::where('email', 'owner@acme.test')->first();
        $all = DealerLink::where('status', DealerLink::ACTIVE)->pluck('id');
        $northern = $all->reject(fn ($id) => $id === $prairie->id);

        $messages = [
            [Broadcast::ALL, $all, 'S-400 cutting edge changes March 1', "From March 1 the S-400 ships with the new 8 ft cutting edge (AS4-1102B).\n\nThe old edge stays available for machines in the field while stock lasts. The updated parts book is under Manuals.", 6],
            [Broadcast::SELECTED, $northern, 'North Dakota farm show booth', "We've reserved booth 14 at the Red River Valley Farm Show. Bring your demo S-600 if you can.", 2],
        ];
        foreach ($messages as [$audience, $links, $title, $body, $daysAgo]) {
            $b = new Broadcast(['title' => $title, 'body' => $body, 'audience' => $audience, 'emailed' => true]);
            $b->forceFill(['sent_by' => $owner->id, 'created_at' => now()->subDays($daysAgo)])->save();
            foreach ($links as $id) {
                $b->recipients()->create(['dealer_link_id' => $id]);
            }
        }
    }

    /** Prairie's parts orders: one shipped, one being prepared, one rush order just in. Inside Tenant::run(Acme). */
    private function partsOrders(DealerLink $link): void
    {
        $dealer = User::where('email', 'dealer@prairie.test')->first();
        $parts = User::where('email', 'owner@acme.test')->first();
        $shipTo = ['name' => 'Prairie Equipment', 'address' => '1200 Victoria Ave E', 'city' => 'Brandon', 'region' => 'MB', 'postal_code' => 'R7A 1Z9', 'country' => 'CA'];

        $orders = [
            // po, serial, method, status, days ago, lines
            ['PE-2291', 'AS4-24002', 'standard', PartsOrder::SHIPPED, 9, [['AS4-1102', 'Cutting edge, 8 ft', 2], ['HW-0450', 'Plow bolt 5/8 x 2', 24]]],
            ['PE-2304', 'AS6-24003', 'standard', PartsOrder::IN_PROGRESS, 2, [['AS6-3310', 'Apron cylinder seal kit', 1]]],
            ['PE-2310', 'AR2-24001', 'rush', PartsOrder::SUBMITTED, 0, [['R2-SHANK-9', 'Ripper shank', 1], ['R2-TIP-2', 'Ripper tip', 3]]],
        ];

        foreach ($orders as $n => [$po, $serial, $method, $status, $ago, $lines]) {
            $order = new PartsOrder(['po_number' => $po, 'machine_serial' => $serial, 'ship_method' => $method, 'ship_to' => $shipTo]);
            $order->forceFill([
                'dealer_link_id' => $link->id, 'number' => PartsOrder::FIRST_NUMBER + $n, 'status' => $status, 'submitted_by' => $dealer->id,
                'handled_by' => $status === PartsOrder::SUBMITTED ? null : $parts->id,
                'started_at' => $status === PartsOrder::SUBMITTED ? null : now()->subDays(max(0, $ago - 1)),
                'shipped_at' => $status === PartsOrder::SHIPPED ? now()->subDays($ago - 2) : null,
                'carrier' => $status === PartsOrder::SHIPPED ? 'Purolator' : null,
                'tracking_number' => $status === PartsOrder::SHIPPED ? '329018827' : null,
                'created_at' => now()->subDays($ago),
            ])->save();
            foreach ($lines as $i => [$part, $description, $qty]) {
                $order->lines()->create(['position' => $i, 'part_number' => $part, 'description' => $description, 'quantity' => $qty]);
            }
        }
    }

    /**
     * Prairie's quotes, in CAD from their list: one won, one sent, one draft.
     * Runs inside Tenant::run(Acme).
     *
     * @param  array<string, ProductModel>  $models
     */
    private function quotes(DealerLink $link, array $models, PriceList $list): void
    {
        $dealer = User::where('email', 'dealer@prairie.test')->first();
        $prices = $list->priceMap();
        $tilt = $models['S-400']->options->firstWhere('name', 'Hydraulic tilt');
        $tires = $models['S-400']->options->firstWhere('name', 'Wide tires');

        $quotes = [
            // customer, status, sent days ago, lines [kind, item, qty, unit cents or null for list], discount
            [['company' => 'Hill Farms', 'contact_name' => 'Dana Hill', 'city' => 'Brandon', 'region' => 'MB'], Quote::WON, 20,
                [['model', $models['S-400'], 1, null], ['option', $tilt, 1, null]], 150000],
            [['company' => 'Red River Earthworks', 'contact_name' => 'Sam Ortiz', 'email' => 'sam@redriver.example'], Quote::SENT, 3,
                [['model', $models['S-600'], 2, null], ['custom', 'Freight to site', 1, 120000]], 0],
            [['contact_name' => 'Jordan Lee', 'phone' => '204-555-0199'], Quote::DRAFT, null,
                [['model', $models['S-400'], 1, null], ['option', $tires, 1, 180000]], 0],
        ];

        foreach ($quotes as $n => [$customer, $status, $sentAgo, $lines, $discount]) {
            $quote = new Quote(['customer' => $customer, 'valid_until' => now()->addDays(30 - ($sentAgo ?? 0))]);
            $quote->forceFill([
                'dealer_link_id' => $link->id, 'number' => Quote::FIRST_NUMBER + $n, 'currency' => $list->currency,
                'status' => $status, 'created_by' => $dealer->id,
                'sent_at' => $sentAgo ? now()->subDays($sentAgo) : null,
                'decided_at' => $status === Quote::WON ? now()->subDays(5) : null,
            ])->save();

            $subtotal = 0;
            foreach ($lines as $i => [$kind, $item, $qty, $unit]) {
                $key = $kind === 'model' ? 'model:'.$item->id : ($kind === 'option' ? 'option:'.$item->id : null);
                $listPrice = $key ? ($prices[$key] ?? null) : null;
                $unit ??= $listPrice;
                $subtotal += $unit * $qty;
                $quote->lines()->create([
                    'position' => $i, 'kind' => $kind, 'item_id' => $kind === 'custom' ? null : $item->id,
                    'description' => match ($kind) {
                        'model' => $item->name, 'option' => $models['S-400']->name.' - '.$item->name, default => $item
                    },
                    'quantity' => $qty, 'list_price_cents' => $listPrice, 'unit_price_cents' => $unit, 'line_total_cents' => $unit * $qty,
                ]);
            }
            $quote->forceFill(['subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'total_cents' => $subtotal - $discount])->save();

            // A won quote is a sales order - here, already in production.
            if ($status === Quote::WON) {
                $order = SalesOrder::fromQuote($quote, User::where('email', 'owner@acme.test')->first());
                // Won when the quote was decided (5 days ago), then into production.
                $order->forceFill(['status' => SalesOrder::IN_PRODUCTION, 'created_at' => now()->subDays(5), 'started_at' => now()->subDays(3), 'po_number' => 'HF-2026-118'])->save();
            }
        }
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
