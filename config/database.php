<?php
declare(strict_types=1);
// Read-only config facade; secrets are loaded from environment/.env outside public.
return \App\Database\ConnectionFactory::config('runtime');
