<?php

namespace Tests\Unit;

use App\User;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;
use Throwable;

class DecrementsTransactionLevelEvenIfCaughtExceptionTest extends TestCase
{
    public function testDecrementsTransactionLevelEvenIfCaughtException(): void
    {
        // First - create something in the DB so we can update it from two different connections to cause a deadlock
        $firstUser = factory(User::class)->create();
        $secondUser = factory(User::class)->create();
        $firstUserKey = $firstUser->getKey();
        $secondUserKey = $secondUser->getKey();

        DB::statement('set innodb_lock_wait_timeout = 25');
        DB::beginTransaction();

        User::whereKey($firstUserKey)
            ->update([
                'id' => $firstUserKey,
            ]);

        dispatch(function () use ($firstUserKey, $secondUserKey) {
            DB::statement('set innodb_lock_wait_timeout = 25');
            DB::beginTransaction();

            User::whereKey($secondUserKey)
                ->update([
                    'id' => $secondUserKey,
                ]);

            sleep(5);

            // Cause lock wait
            User::whereKey($firstUserKey)
                ->update([
                    'id' => $firstUserKey,
                ]);
        });

        // Both users updated first time by this point
        sleep(4);

        // Create a savepoint
        DB::beginTransaction();

        $caughtException = null;

        try {
            // Cause a deadlock on this connection
            User::whereKey($secondUserKey)
                ->update([
                    'id' => $secondUserKey,
                ]);
        } catch (Throwable $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException);
        $this->assertInstanceOf(PDOException::class, $caughtException);

        // will fail as savepoint doesn't exist anymore
        // PDOException : SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT trans2 does not exist
        DB::rollBack();
    }
}
