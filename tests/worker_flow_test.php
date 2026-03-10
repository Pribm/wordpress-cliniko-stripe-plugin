<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/../');

final class FakeWpdb
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $dbname = 'wpdb_test';

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        return $query . ' | ' . json_encode($args);
    }

    /**
     * @return mixed
     */
    public function get_var(string $query)
    {
        if (!empty($GLOBALS['__wpdb_get_var_queue'])) {
            return array_shift($GLOBALS['__wpdb_get_var_queue']);
        }

        return 1;
    }

    public function query(string $query): int
    {
        $GLOBALS['__wpdb_queries'][] = $query;
        return 1;
    }

    /**
     * @return mixed
     */
    public function get_row(string $query, string $output = 'ARRAY_A')
    {
        return $GLOBALS['__wpdb_get_row_result'] ?? ['status' => 'started'];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $GLOBALS['__wpdb_updates'][] = [
            'table' => $table,
            'data' => $data,
            'where' => $where,
        ];
        return 1;
    }

    public function get_charset_collate(): string
    {
        return '';
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (!function_exists('add_option')) {
    /**
     * @param mixed $value
     */
    function add_option(string $name, $value, string $deprecated = '', bool $autoload = true): bool
    {
        if (array_key_exists($name, $GLOBALS['__wp_options'])) {
            return false;
        }
        $GLOBALS['__wp_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $name, $value, bool $autoload = true): bool
    {
        $GLOBALS['__wp_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $name)
    {
        return $GLOBALS['__wp_transients'][$name] ?? false;
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $name, $value, int $ttl): bool
    {
        $GLOBALS['__wp_transients'][$name] = $value;
        return true;
    }
}

if (!function_exists('wp_cache_add')) {
    /**
     * @param mixed $value
     */
    function wp_cache_add(string $key, $value, string $group = '', int $ttl = 0): bool
    {
        if (array_key_exists($key, $GLOBALS['__wp_cache'])) {
            return false;
        }
        $GLOBALS['__wp_cache'][$key] = $value;
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql'): string
    {
        return '2026-02-16 10:00:00';
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}

if (!function_exists('add_action')) {
    /**
     * @param callable|array<int|string,mixed> $callback
     */
    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
    }
}
}

namespace App\Client\Cliniko {
    class Client
    {
        /**
         * @return object
         */
        public static function getInstance()
        {
            return (object) ['fake' => true];
        }
    }
}

namespace App\DTO {
    class CreatePatientDTO
    {
        /** @var mixed */
        public $firstName;
        /** @var mixed */
        public $lastName;
        /** @var mixed */
        public $email;
        /** @var mixed */
        public $address1;
        /** @var mixed */
        public $address2;
        /** @var mixed */
        public $city;
        /** @var mixed */
        public $postCode;
        /** @var mixed */
        public $dateOfBirth;
        /** @var mixed */
        public $medicare;
        /** @var mixed */
        public $medicareReferenceNumber;
        /** @var mixed */
        public $patientPhoneNumbers;
        /** @var mixed */
        public $acceptedPrivacyPolicy;
    }

    class CreatePatientFormDTO
    {
        /** @var mixed */
        public $completed;
        /** @var mixed */
        public $content_sections;
        /** @var mixed */
        public $business_id;
        /** @var mixed */
        public $patient_form_template_id;
        /** @var mixed */
        public $patient_id;
        /** @var mixed */
        public $appointment_id;
        /** @var mixed */
        public $attendee_id;
        /** @var mixed */
        public $email_to_patient_on_completion;
        /** @var mixed */
        public $name;
    }
}

namespace App\Model {
    class AppointmentType
    {
        /** @var object|null */
        public static $instance;

        /**
         * @return object|null
         */
        public static function find(string $id, $client)
        {
            return self::$instance;
        }

        /**
         * @return array<int,object>
         */
        public static function all($client): array
        {
            return self::$instance ? [self::$instance] : [];
        }
    }

    class Booking
    {
        /** @var object|null */
        public static $instance;

        /**
         * @return object|null
         */
        public static function find(string $id, $client)
        {
            return self::$instance;
        }
    }

    class IndividualAppointment
    {
        /** @var array<int,array<string,mixed>> */
        public static $createdPayloads = [];

        /** @var object|null */
        public static $instance;

        /**
         * @param array<string,mixed> $data
         * @return object|null
         */
        public static function create(array $data, $client)
        {
            self::$createdPayloads[] = $data;
            return self::$instance;
        }
    }

    class PatientForm
    {
        /** @var array<int,mixed> */
        public static $createdDtos = [];

        /** @var object|null */
        public static $instance;

        /**
         * @return object|null
         */
        public static function create($dto, $client)
        {
            self::$createdDtos[] = $dto;

            if (!empty($GLOBALS['__patient_form_should_fail'])) {
                throw new \RuntimeException('Simulated patient form failure');
            }

            return self::$instance;
        }
    }

    class PatientFormTemplate
    {
        /** @var object|null */
        public static $instance;

        /**
         * @return object|null
         */
        public static function find(string $id, $client)
        {
            return self::$instance;
        }

        /**
         * @return array<int,object>
         */
        public static function all($client): array
        {
            return self::$instance ? [self::$instance] : [];
        }
    }
}

namespace App\Service {
    class ClinikoService
    {
        /**
         * @return object|null
         */
        public function findOrCreatePatient($createPatientDto)
        {
            return $GLOBALS['__fake_patient_entity'] ?? null;
        }

        /**
         * @return object|null
         */
        public function getNextAvailableTime(
            string $businessId,
            string $practitionerId,
            string $appointmentTypeId,
            string $from,
            string $to,
            $client
        ) {
            return $GLOBALS['__fake_next_available_time'] ?? null;
        }
    }

    class StripeService
    {
        /** @var array<int,array<string,mixed>> */
        public static $refunds = [];

        /**
         * @param array<string,mixed> $metadata
         * @return object
         */
        public function createChargeFromToken(
            string $token,
            int $amount,
            string $description = '',
            array $metadata = [],
            ?string $receiptEmail = null
        ) {
            return (object) [
                'id' => 'ch_fake_1',
                'receipt_url' => null,
                'payment_method_details' => (object) [
                    'card' => (object) [
                        'last4' => '4242',
                        'brand' => 'visa',
                    ],
                ],
            ];
        }

        /**
         * @param array<string,mixed> $metadata
         * @return object
         */
        public function refundCharge(string $chargeId, ?int $amount = null, ?string $reason = null, array $metadata = [])
        {
            self::$refunds[] = [
                'chargeId' => $chargeId,
                'amount' => $amount,
                'reason' => $reason,
                'metadata' => $metadata,
            ];
            return (object) ['id' => 're_fake_1'];
        }
    }

    class NotificationService
    {
        /** @var array<int,array<string,mixed>> */
        public static $successCalls = [];

        /** @var array<int,array<string,mixed>> */
        public static $failureCalls = [];

        /**
         * @param array<string,mixed> $args
         * @param array<string,mixed> $patient
         */
        public function sendSuccess(array $args, array $patient, $paymentRef, ?int $amountCents): void
        {
            self::$successCalls[] = [
                'args' => $args,
                'patient' => $patient,
                'paymentRef' => $paymentRef,
                'amount' => $amountCents,
            ];
        }

        /**
         * @param array<string,mixed> $args
         * @param array<string,mixed> $patient
         */
        public function sendFailure(array $args, array $patient, $paymentRef, ?int $amountCents): void
        {
            self::$failureCalls[] = [
                'args' => $args,
                'patient' => $patient,
                'paymentRef' => $paymentRef,
                'amount' => $amountCents,
            ];
        }
    }
}

namespace {
    require __DIR__ . '/../src/Workers/ClinikoSchedulingWorker.php';

    use App\Model\AppointmentType;
    use App\Model\Booking;
    use App\Model\IndividualAppointment;
    use App\Model\PatientForm;
    use App\Model\PatientFormTemplate;
    use App\Service\NotificationService;
    use App\Service\StripeService;
    use App\Workers\ClinikoSchedulingWorker;

    function reset_worker_state(): void
    {
        global $wpdb;

        $wpdb = new \FakeWpdb();

        $GLOBALS['__wp_options'] = [
            'wp_cliniko_business_id' => 'business_1',
        ];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_cache'] = [];

        $GLOBALS['__wpdb_get_var_queue'] = [];
        $GLOBALS['__wpdb_queries'] = [];
        $GLOBALS['__wpdb_updates'] = [];
        $GLOBALS['__wpdb_get_row_result'] = ['status' => 'started'];

        $GLOBALS['__patient_form_should_fail'] = false;

        $GLOBALS['__fake_patient_entity'] = new class {
            public function getId(): string
            {
                return 'patient_1';
            }
        };

        $GLOBALS['__fake_next_available_time'] = (object) ['appointmentStart' => '2026-02-20T03:00:00Z'];

        AppointmentType::$instance = new class {
            public function getId(): string
            {
                return 'appt_type_1';
            }

            public function getDurationInMinutes(): int
            {
                return 30;
            }
        };

        PatientFormTemplate::$instance = new class {
            public function getName(): string
            {
                return 'Template Name';
            }
        };

        IndividualAppointment::$createdPayloads = [];
        IndividualAppointment::$instance = new class {
            public function getId(): string
            {
                return 'appointment_1';
            }
        };

        Booking::$instance = new class {
            /**
             * @return array<int,object>
             */
            public function getAttendees(): array
            {
                return [
                    new class {
                        public function getId(): string
                        {
                            return 'attendee_1';
                        }
                    },
                ];
            }
        };

        PatientForm::$createdDtos = [];
        PatientForm::$instance = new class {
            public function getId(): string
            {
                return 'patient_form_1';
            }
        };

        NotificationService::$successCalls = [];
        NotificationService::$failureCalls = [];
        StripeService::$refunds = [];
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    function assert_same($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
            );
        }
    }

    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function test_worker_free_success_path_sends_success_notification(): void
    {
        $args = [
            'moduleId' => '123',
            'patient_form_template_id' => '999',
            'payment_reference' => null,
            'amount' => 0,
            'currency' => 'aud',
            'appointment_label' => 'Consult',
            'patient' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'practitioner_id' => 'practitioner_1',
                'appointment_start' => '2026-02-20T03:00:00Z',
                'phone' => '0412345678',
            ],
            'content' => [
                'sections' => [],
            ],
        ];

        ClinikoSchedulingWorker::handle($args);

        assert_same(1, count(NotificationService::$successCalls), 'Expected one success notification');
        assert_same(0, count(NotificationService::$failureCalls), 'Expected zero failure notifications');
        assert_same(0, count(StripeService::$refunds), 'Expected zero refunds on success');
        assert_same(1, count(IndividualAppointment::$createdPayloads), 'Expected appointment to be created');
        assert_same(1, count(PatientForm::$createdDtos), 'Expected patient form to be created');
    }

    function test_worker_paid_failure_triggers_refund_and_failure_notification(): void
    {
        $GLOBALS['__wpdb_get_var_queue'] = [1];

        // Force validation failure inside worker try/catch after ledger claim.
        $args = [
            'payment_reference' => 'ch_test_123',
            'amount' => 12500,
            'currency' => 'aud',
            'patient' => [
                'email' => 'john@example.com',
            ],
        ];

        ClinikoSchedulingWorker::handle($args);

        assert_same(0, count(NotificationService::$successCalls), 'Expected no success notifications');
        assert_same(1, count(NotificationService::$failureCalls), 'Expected one failure notification');
        assert_same(1, count(StripeService::$refunds), 'Expected one refund on paid failure');

        $refund = StripeService::$refunds[0];
        assert_same('ch_test_123', $refund['chargeId'], 'Refund should target payment reference');
        assert_same(12500, $refund['amount'], 'Refund amount should match worker amount');
        assert_same('requested_by_customer', $refund['reason'], 'Refund reason mismatch');
    }

    function test_worker_patient_form_failure_is_not_reported_as_success(): void
    {
        $GLOBALS['__patient_form_should_fail'] = true;

        $args = [
            'moduleId' => '123',
            'patient_form_template_id' => '999',
            'payment_reference' => null,
            'amount' => 0,
            'currency' => 'aud',
            'appointment_label' => 'Consult',
            'patient' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'practitioner_id' => 'practitioner_1',
                'appointment_start' => '2026-02-20T03:00:00Z',
            ],
            'content' => [
                'sections' => [],
            ],
        ];

        ClinikoSchedulingWorker::handle($args);

        assert_same(0, count(NotificationService::$successCalls), 'Patient form failure must not send success notification');
        assert_same(1, count(NotificationService::$failureCalls), 'Patient form failure should send one failure notification');
        assert_same(0, count(StripeService::$refunds), 'Free flow should not create refunds');
        assert_same(1, count(IndividualAppointment::$createdPayloads), 'Appointment may already exist when form attach fails');
        assert_same(1, count(PatientForm::$createdDtos), 'Patient form creation should have been attempted');
    }

    function test_worker_mutex_prevents_duplicate_processing_for_same_lock_source(): void
    {
        $args = [
            'moduleId' => '123',
            'patient_form_template_id' => '999',
            'payment_reference' => null,
            'amount' => 0,
            'currency' => 'aud',
            'patient' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'practitioner_id' => 'practitioner_1',
                'appointment_start' => '2026-02-20T03:00:00Z',
            ],
            'content' => [
                'sections' => [],
            ],
            'payload_key' => 'same_payload_key',
        ];

        ClinikoSchedulingWorker::handle($args);
        ClinikoSchedulingWorker::handle($args);

        assert_same(1, count(NotificationService::$successCalls), 'Second run should be skipped by mutex');
    }

    /**
     * @param callable():void $test
     */
    function run_test(string $name, callable $test): bool
    {
        reset_worker_state();

        try {
            $test();
            echo "[PASS] {$name}\n";
            return true;
        } catch (Throwable $e) {
            echo "[FAIL] {$name}: " . get_class($e) . ': ' . $e->getMessage() . " ({$e->getFile()}:{$e->getLine()})\n";
            return false;
        }
    }

    $tests = [
        'worker_free_success_path_sends_success_notification' => 'test_worker_free_success_path_sends_success_notification',
        'worker_paid_failure_triggers_refund_and_failure_notification' => 'test_worker_paid_failure_triggers_refund_and_failure_notification',
        'worker_patient_form_failure_is_not_reported_as_success' => 'test_worker_patient_form_failure_is_not_reported_as_success',
        'worker_mutex_prevents_duplicate_processing_for_same_lock_source' => 'test_worker_mutex_prevents_duplicate_processing_for_same_lock_source',
    ];

    $passed = 0;
    foreach ($tests as $name => $fn) {
        if (run_test($name, $fn)) {
            $passed++;
        }
    }

    $total = count($tests);
    echo "\n{$passed}/{$total} worker tests passed.\n";

    exit($passed === $total ? 0 : 1);
}
