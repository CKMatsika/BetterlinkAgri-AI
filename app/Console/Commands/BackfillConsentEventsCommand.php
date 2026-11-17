<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Consent\BackfillConsentEventsAction;
use Illuminate\Console\Command;

final class BackfillConsentEventsCommand extends Command
{
    protected $signature = 'consent:backfill-events';

    protected $description = 'Idempotently backfill legacy consents and location_consents into consent_events.';

    public function handle(BackfillConsentEventsAction $action): int
    {
        $result = $action->execute();

        $this->info(sprintf(
            'Backfill complete: %d consents, %d location consents processed, %d consent_events created.',
            $result['consents_processed'],
            $result['location_consents_processed'],
            $result['events_created']
        ));

        return self::SUCCESS;
    }
}
