<?php
namespace App\Service;

if (!defined('ABSPATH')) exit;

use App\Model\AppointmentTypeList;
use App\Model\AppointmentType;
use App\Model\LinkedResource;

class ClinikoService
{
    protected string $authHeader;
    protected string $BASE_URL;

    public function __construct()
    {
        $this->authHeader = 'Basic ' . base64_encode(get_option('wp_cliniko_api_key') . ':');
        $this->BASE_URL = "https://api.au4.cliniko.com/v1/";
    }

    protected function request(string $endpointOrUrl): ?array
    {
        $url = str_starts_with($endpointOrUrl, 'http')
            ? $endpointOrUrl
            : $this->BASE_URL . $endpointOrUrl;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => $this->authHeader,
                'Accept' => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('[ClinikoService] Erro na requisição: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    protected function getPriceFromLink(string $url): ?float
    {
        $data = $this->request($url);
        return isset($data['price']) ? (float) $data['price'] : null;
    }

    /**
     * @return AppointmentType[]
     */
    public function getAppointmentTypes(): array
    {
        try {
            $response = $this->request('appointment_types');

            if (!isset($response['appointment_types'])) {
                throw new \UnexpectedValueException('Resposta da API não contém appointment_types.');
            }

            $appointmentList = AppointmentTypeList::fromArray($response);

            foreach ($appointmentList->appointmentTypes as $appointmentType) {
                $priceInCents = 0;

                if ($appointmentType->billableItems) {
                    try {
                        $billableItemsResponse = $this->request($appointmentType->billableItems->url);

                        foreach ($billableItemsResponse['appointment_type_billable_items'] ?? [] as $item) {
                            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1;
                            $billableItemUrl = $item['billable_item']['links']['self'] ?? null;

                            if ($billableItemUrl) {
                                $unitPrice = $this->getPriceFromLink($billableItemUrl);
                                $unitPriceCents = (int) round($unitPrice * 100);
                                $priceInCents += (int) round($unitPriceCents * $quantity);
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('[ClinikoService] Erro ao carregar billable items inline: ' . $e->getMessage());
                    }
                }

                if ($priceInCents === 0 && $appointmentType->billableItem) {
                    $unitPrice = $this->getPriceFromLink($appointmentType->billableItem->url);
                    $priceInCents = (int) round(($unitPrice ?? 0) * 100);
                }

                if ($priceInCents === 0 && $appointmentType->product) {
                    $unitPrice = $this->getPriceFromLink($appointmentType->product->url);
                    $priceInCents = (int) round(($unitPrice ?? 0) * 100);
                }

                $appointmentType->price = $priceInCents;
            }

            return $appointmentList->appointmentTypes;

        } catch (\Throwable $e) {
            error_log('[ClinikoService] Erro ao obter tipos de agendamento: ' . $e->getMessage());
            throw new \RuntimeException('Erro ao processar dados dos tipos de agendamento.');
        }
    }

    public function findPatientByNameAndEmail(string $firstName, string $lastName, string $email): ?array
    {
        $query = "{$firstName} {$lastName} {$email}";
        $endpoint = 'patients?search=' . urlencode($query);

        $response = wp_remote_get($this->BASE_URL . $endpoint, [
            'headers' => [
                'Authorization' => $this->authHeader,
                'Accept' => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('[ClinikoService] Erro ao buscar paciente: ' . $response->get_error_message());
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['patients'])) {
            return $data['patients'][0];
        }

        return null;
    }

    public function createPatient(array $data): ?array
    {
        $response = wp_remote_post($this->BASE_URL . "patients", [
            'headers' => [
                'Authorization' => $this->authHeader,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($data)
        ]);

        if (is_wp_error($response)) {
            error_log('[ClinikoService] Erro ao criar paciente: ' . $response->get_error_message());
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

  public function findNextAvailableSlot(int $appointmentTypeId): ?array
{
    $businessId = get_option('wp_cliniko_business_id');
    if (!$businessId) {
        throw new \RuntimeException("Business ID não configurado.");
    }

    $from = date('Y-m-d'); // hoje
    $to = date('Y-m-d', strtotime('+7 days')); // até 7 dias depois

    $practitioners = $this->request('practitioners')['practitioners'] ?? [];

    foreach ($practitioners as $practitioner) {
        $practitionerId = $practitioner['id'];
        
        $endpoint = "https://api.au1.cliniko.com/v1/businesses/{$businessId}/practitioners/{$practitionerId}/appointment_types/{$appointmentTypeId}/next_available_time?from={$from}&to={$to}";
        $response = $this->request($endpoint);

        if (!empty($response['next_available_time'])) {
            return [
                'practitioner_id' => $practitionerId,
                'start_time' => $response['next_available_time']
            ];
        }
    }

    return null; 
}


    public function createAppointmentWithPatient(array $data): ?array
    {
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $email = $data['email'] ?? '';

        $existing = $this->findPatientByNameAndEmail($firstName, $lastName, $email);

        if ($existing && !empty($existing['id'])) {
            $patientId = $existing['id'];
        } else {
            $patient = $this->createPatient($data);
            $patientId = $patient['id'] ?? null;
        }

        if (!$patientId) {
            throw new \RuntimeException("Não foi possível identificar ou criar o paciente.");
        }

        $slot = $this->findNextAvailableSlot($data['appointment_type_id']);

        if (!$slot) {
            throw new \RuntimeException("Nenhum horário disponível encontrado.");
        }

        $appointmentPayload = [
            "patient_id" => $patientId,
            "appointment_type_id" => $data['appointment_type_id'],
            "start_time" => $slot['start_time'],
            "practitioner_id" => $slot['practitioner_id'],
        ];


        $response = wp_remote_post($this->BASE_URL . "appointments", [
            'headers' => [
                'Authorization' => $this->authHeader,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($appointmentPayload)
        ]);

        if (is_wp_error($response)) {
            error_log('[ClinikoService] Erro ao criar agendamento: ' . $response->get_error_message());
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function getBillableItemListFromLink(LinkedResource $link): BillableItemList
    {
        $response = $this->request($link->url);

        if (!$response || !isset($response['billable_items'])) {
            throw new \RuntimeException('Erro ao obter os itens faturáveis.');
        }

        return BillableItemList::fromArray($response);
    }
}
