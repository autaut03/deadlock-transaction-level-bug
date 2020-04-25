<?php


namespace Tests\Unit;


use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

        sleep(5);

//    $secondConnection->transaction(function () use ($secondConnection, $secondConnectionName, $user) {
//        $caughtException = null;
//
//        // laravel transaction level = 1, mysql transaction level = 1
//        $secondConnection->beginTransaction();
//        $this->assertSame(1, $secondConnection->transactionLevel());
//
//        // laravel transaction level = 2, mysql transaction level = 2
//        $secondConnection->beginTransaction();
//        $this->assertSame(2, $secondConnection->transactionLevel());
//
//        try {
//            // Cause a deadlock
//            $firstUser
//                ->setConnection($secondConnectionName)
//                ->whereKey($firstUser->getKey())
//                ->update([
//                    'email' => 'sdad@sdad.sad123',
//                ]);
//        } catch (Throwable $e) {
//            $this->assertInstanceOf(PDOException::class, $e);
//
//            // do something, but don't re-throw
//            // laravel transaction level = 2, mysql transaction level = 1
//            $caughtException = $e;
//        }
//
//        $this->assertNotNull($caughtException);
//
//        // laravel transaction level = 1, mysql transaction level = 0
//        $secondConnection->rollBack();
//
//        // laravel transaction level = 0, mysql transaction level = -1 ?
//        $secondConnection->rollBack();

        // Must be 1 as DBMS has already decreased the level, but it is in fact 2
//      $this->assertSame(1, $secondConnection->transactionLevel());

//      $secondConnection->beginTransaction();
//      $secondConnection->beginTransaction();
//      $secondConnection->rollBack();
//      $secondConnection->rollBack();
        // We now rollback.
//      $secondConnection->rollBack();

        // J
//      throw new RuntimeException();
//    });
    }
}
