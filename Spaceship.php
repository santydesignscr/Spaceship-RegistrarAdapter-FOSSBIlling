<?php

declare(strict_types=1);

/**
 * Registrar adapter for Spaceship (spaceship.com), for FOSSBilling.
 *
 * API docs: https://docs.spaceship.dev/
 * Generate an API key/secret in the Spaceship API Manager:
 * https://www.spaceship.com/application/api-manager/
 *
 * Registration, renewal, restoration and transfer are asynchronous on
 * Spaceship's side (HTTP 202 + polling via /async-operations/{id}). This
 * adapter polls internally so the required registerDomain()/renewDomain()/
 * transferDomain() can return a plain bool, which means the call blocks for
 * up to ASYNC_TIMEOUT seconds. Use the *Async() variants to avoid blocking.
 */
class Registrar_Adapter_Spaceship extends Registrar_AdapterAbstract
{
    private const API_BASE = 'https://spaceship.dev/api/v1';
    private const ASYNC_TIMEOUT = 60;
    private const ASYNC_POLL_INTERVAL = 2;

    protected array $config = [
        'api_key' => null,
        'api_secret' => null,
        'default_privacy_level' => 'high',
    ];

    public function __construct($options)
    {
        if (empty($options['api_key'])) {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'Spaceship', ':missing' => 'API Key'],
                3001
            );
        }
        $this->config['api_key'] = $options['api_key'];

        if (empty($options['api_secret'])) {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'Spaceship', ':missing' => 'API Secret'],
                3001
            );
        }
        $this->config['api_secret'] = $options['api_secret'];

        $this->config['default_privacy_level'] = $options['default_privacy_level'] ?? 'high';
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on Spaceship (spaceship.com) via API. Generate an API key and secret in the Spaceship API Manager (Account &rarr; API Manager) and enable the domains/contacts/dnsrecords scopes you plan to use.',
            'form' => [
                'api_key' => ['text', [
                    'label' => 'API Key',
                    'required' => true,
                ]],
                'api_secret' => ['password', [
                    'label' => 'API Secret',
                    'required' => true,
                ]],
                'default_privacy_level' => ['select', [
                    'label' => 'Default WHOIS privacy level',
                    'multiOptions' => ['high' => 'High (redacted)', 'public' => 'Public'],
                    'value' => 'high',
                ]],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $response = $this->apiRequest('GET', '/domains/' . rawurlencode($domain->getName()) . '/available');

        return ($response['result'] ?? null) === 'available';
    }

    /**
     * @param string[] $domainNames
     * @return array<string, bool>
     */
    public function checkMultipleAvailability(array $domainNames): array
    {
        if (count($domainNames) === 0 || count($domainNames) > 20) {
            throw new Registrar_Exception('Spaceship availability checks accept between 1 and 20 domains per request');
        }

        $response = $this->apiRequest('POST', '/domains/available', [
            'domains' => array_values($domainNames),
        ]);

        $results = [];
        foreach ($response['domains'] ?? [] as $item) {
            $results[$item['domain']] = ($item['result'] ?? null) === 'available';
        }

        return $results;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        return !$this->isDomainAvailable($domain);
    }

    // ------------------------------------------------------------------
    // Registration / renewal / restoration / deletion
    // ------------------------------------------------------------------

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $operationId = $this->registerDomainAsync($domain);
        $operation = $this->waitForAsyncOperation($operationId);

        return ($operation['status'] ?? null) === 'success';
    }

    public function registerDomainAsync(Registrar_Domain $domain): string
    {
        $contactId = $this->saveContact($domain->getContactRegistrar());
        $years = $this->normalizeYears($domain->getRegistrationPeriod());

        [$status, $decoded, $operationId] = $this->rawRequest('POST', '/domains/' . rawurlencode($domain->getName()), [
            // Always off: domains are only ever renewed manually.
            'autoRenew' => false,
            'years' => $years,
            'privacyProtection' => [
                'level' => $this->config['default_privacy_level'],
                'userConsent' => true,
            ],
            'contacts' => [
                'registrant' => $contactId,
                'admin' => $contactId,
                'tech' => $contactId,
                'billing' => $contactId,
            ],
        ]);

        if ($status !== 202 || !$operationId) {
            $this->assertSuccess($status, $decoded);
            throw new Registrar_Exception('Spaceship registration did not return an async operation id as expected');
        }

        return $operationId;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $operationId = $this->renewDomainAsync($domain);
        $operation = $this->waitForAsyncOperation($operationId);

        return ($operation['status'] ?? null) === 'success';
    }

    public function renewDomainAsync(Registrar_Domain $domain): string
    {
        [$status, $decoded, $operationId] = $this->rawRequest('POST', '/domains/' . rawurlencode($domain->getName()) . '/renew', [
            'years' => $this->normalizeYears($domain->getRegistrationPeriod()),
            'currentExpirationDate' => $this->toIso8601UtcDateTime($domain->getExpirationTime()),
        ]);

        if ($status !== 202 || !$operationId) {
            $this->assertSuccess($status, $decoded);
            throw new Registrar_Exception('Spaceship renewal did not return an async operation id as expected');
        }

        return $operationId;
    }

    /** Restore a domain within its redemption grace period. */
    public function restoreDomain(Registrar_Domain $domain): bool
    {
        [$status, $decoded, $operationId] = $this->rawRequest('POST', '/domains/' . rawurlencode($domain->getName()) . '/restore');

        if ($status !== 202 || !$operationId) {
            $this->assertSuccess($status, $decoded);
            throw new Registrar_Exception('Spaceship restore did not return an async operation id as expected');
        }

        $operation = $this->waitForAsyncOperation($operationId);

        return ($operation['status'] ?? null) === 'success';
    }

    /** Spaceship does not support domain deletion via the API (returns HTTP 501). */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Spaceship does not support domain deletion via the API. Contact Spaceship support to delete a domain, and note no refund is issued.');
    }

    // ------------------------------------------------------------------
    // Domain info / settings
    // ------------------------------------------------------------------

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $data = $this->apiRequest('GET', '/domains/' . rawurlencode($domain->getName()));

        if (isset($data['registrationDate'])) {
            $domain->setRegistrationTime(strtotime((string) $data['registrationDate']));
        }
        if (isset($data['expirationDate'])) {
            $domain->setExpirationTime(strtotime((string) $data['expirationDate']));
        }
        if (isset($data['privacyProtection']['level'])) {
            $domain->setPrivacyEnabled($data['privacyProtection']['level'] === 'high');
        }

        $hosts = $data['nameservers']['hosts'] ?? [];
        if (is_array($hosts)) {
            $hosts = array_values($hosts);
            if (isset($hosts[0])) {
                $domain->setNs1($hosts[0]);
            }
            if (isset($hosts[1])) {
                $domain->setNs2($hosts[1]);
            }
            if (isset($hosts[2])) {
                $domain->setNs3($hosts[2]);
            }
            if (isset($hosts[3])) {
                $domain->setNs4($hosts[3]);
            }
        }

        $registrantContactId = $data['contacts']['registrant'] ?? null;
        if ($registrantContactId) {
            $contactData = $this->apiRequest('GET', '/contacts/' . rawurlencode($registrantContactId));

            $c = new Registrar_Domain_Contact();
            $c->setId($registrantContactId)
                ->setName(trim(($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')))
                ->setEmail($contactData['email'] ?? '')
                ->setCompany($contactData['organization'] ?? '')
                ->setAddress1($contactData['address1'] ?? '')
                ->setCity($contactData['city'] ?? '')
                ->setState($contactData['stateProvince'] ?? '')
                ->setZip($contactData['postalCode'] ?? '')
                ->setCountry($contactData['country'] ?? '');

            if (!empty($contactData['address2'])) {
                $c->setAddress2($contactData['address2']);
            }

            [$telCc, $tel] = $this->splitEppPhone($contactData['phone'] ?? '');
            $c->setTelCc($telCc)->setTel($tel);

            $domain->setContactRegistrar($c);
        }

        return $domain;
    }

    public function getDomainList(int $take = 100, int $skip = 0): array
    {
        $response = $this->apiRequest('GET', '/domains?' . http_build_query([
            'take' => $take,
            'skip' => $skip,
        ]));

        return $response['items'] ?? [];
    }

    public function changeAutoRenew(string $domainName, bool $enabled): bool
    {
        $response = $this->apiRequest('PUT', '/domains/' . rawurlencode($domainName) . '/autorenew', [
            'isEnabled' => $enabled,
        ]);

        return ($response['isEnabled'] ?? null) === $enabled;
    }

    /**
     * Replace which nameservers a domain delegates to. Pass no nameservers
     * on $domain to switch back to Spaceship's own (basic) nameservers.
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $hosts = array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ], static fn ($host) => $host !== null && $host !== ''));

        if (count($hosts) === 0) {
            $this->apiRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/nameservers', [
                'provider' => 'basic',
            ]);

            return true;
        }

        $count = count($hosts);
        if ($count < 2 || $count > 12) {
            throw new Registrar_Exception(sprintf('Spaceship custom nameservers require between 2 and 12 hosts, got %d', $count));
        }
        foreach ($hosts as $host) {
            if (!preg_match('/^(?=.{4,255}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) {
                throw new Registrar_Exception(sprintf('"%s" does not look like a valid fully-qualified nameserver hostname', $host));
            }
        }

        $this->apiRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/nameservers', [
            'provider' => 'custom',
            'hosts' => $hosts,
        ]);

        return true;
    }

    public function getNameservers(string $domainName): array
    {
        $data = $this->apiRequest('GET', '/domains/' . rawurlencode($domainName));

        return $data['nameservers']['hosts'] ?? [];
    }

    // ------------------------------------------------------------------
    // Personal nameservers (glue records)
    // ------------------------------------------------------------------

    /** @return array<int, array{host: string, ips: string[]}> */
    public function getPersonalNameservers(string $domainName): array
    {
        $response = $this->apiRequest('GET', '/domains/' . rawurlencode($domainName) . '/personal-nameservers');

        return $response['records'] ?? [];
    }

    /**
     * $host is only the label under the domain (e.g. "ns1" for
     * ns1.yourdomain.com), not the full hostname.
     */
    public function setPersonalNameserver(string $domainName, string $host, array $ips): bool
    {
        if (str_contains($host, '.')) {
            throw new Registrar_Exception('Personal nameserver host must be just the label under the domain (e.g. "ns1"), not a full hostname');
        }
        if (count($ips) < 1 || count($ips) > 16) {
            throw new Registrar_Exception('Personal nameserver requires between 1 and 16 IP addresses');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new Registrar_Exception(sprintf('"%s" is not a valid IPv4/IPv6 address', $ip));
            }
        }

        $this->apiRequest('PUT', '/domains/' . rawurlencode($domainName) . '/personal-nameservers/' . rawurlencode($host), [
            'host' => $host,
            'ips' => array_values($ips),
        ]);

        return true;
    }

    public function deletePersonalNameserver(string $domainName, string $host): bool
    {
        [$status, $decoded, ] = $this->rawRequest('DELETE', '/domains/' . rawurlencode($domainName) . '/personal-nameservers/' . rawurlencode($host));
        $this->assertSuccess($status, $decoded);

        return true;
    }

    // ------------------------------------------------------------------
    // Privacy
    // ------------------------------------------------------------------

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->setPrivacyLevel($domain, 'high');
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        return $this->setPrivacyLevel($domain, 'public');
    }

    private function setPrivacyLevel(Registrar_Domain $domain, string $level): bool
    {
        [$status, $decoded, ] = $this->rawRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/privacy/preference', [
            'privacyLevel' => $level,
            'userConsent' => true,
        ]);
        $this->assertSuccess($status, $decoded);

        $domain->setPrivacyEnabled($level === 'high');

        return true;
    }

    // ------------------------------------------------------------------
    // Transfer
    // ------------------------------------------------------------------

    public function getEpp(Registrar_Domain $domain)
    {
        $response = $this->apiRequest('GET', '/domains/' . rawurlencode($domain->getName()) . '/transfer/auth-code');

        return $response['authCode'] ?? '';
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $operationId = $this->transferDomainAsync($domain);
        $operation = $this->waitForAsyncOperation($operationId);

        return ($operation['status'] ?? null) === 'success';
    }

    public function transferDomainAsync(Registrar_Domain $domain): string
    {
        $contactId = $this->saveContact($domain->getContactRegistrar());

        [$status, $decoded, $operationId] = $this->rawRequest('POST', '/domains/' . rawurlencode($domain->getName()) . '/transfer', [
            // Always off: domains are only ever renewed manually.
            'autoRenew' => false,
            'privacyProtection' => [
                'level' => $this->config['default_privacy_level'],
                'userConsent' => true,
            ],
            'contacts' => [
                'registrant' => $contactId,
                'admin' => $contactId,
                'tech' => $contactId,
                'billing' => $contactId,
            ],
            'authCode' => $domain->getEpp(),
        ]);

        if ($status !== 202 || !$operationId) {
            $this->assertSuccess($status, $decoded);
            throw new Registrar_Exception('Spaceship transfer did not return an async operation id as expected');
        }

        return $operationId;
    }

    public function getTransferStatus(string $domainName): array
    {
        return $this->apiRequest('GET', '/domains/' . rawurlencode($domainName) . '/transfer');
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $response = $this->apiRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/transfer/lock', ['isLocked' => true]);

        return ($response['isLocked'] ?? null) === true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $response = $this->apiRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/transfer/lock', ['isLocked' => false]);

        return ($response['isLocked'] ?? null) === false;
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $contactId = $this->saveContact($domain->getContactRegistrar());

        $response = $this->apiRequest('PUT', '/domains/' . rawurlencode($domain->getName()) . '/contacts', [
            'registrant' => $contactId,
            'admin' => $contactId,
            'tech' => $contactId,
            'billing' => $contactId,
        ]);

        if (($response['verificationStatus'] ?? null) === 'verification') {
            $this->getLog()->info(sprintf(
                'Spaceship requires the registrant of %s to confirm their email before this contact change is fully applied.',
                $domain->getName()
            ));
        }

        return true;
    }

    /** Always creates a new Spaceship contact; there is no update-in-place endpoint. */
    private function saveContact(Registrar_Domain_Contact $contact): string
    {
        $response = $this->apiRequest('PUT', '/contacts', $this->normalizeContact($contact));

        $contactId = $response['contactId'] ?? null;
        if (!$contactId) {
            throw new Registrar_Exception('Spaceship did not return a contact id when saving contact details');
        }

        return $contactId;
    }

    private function normalizeContact(Registrar_Domain_Contact $contact): array
    {
        $firstName = (string) $contact->getFirstName();
        $lastName = (string) $contact->getLastName();
        if ($firstName === '' && $lastName === '') {
            $parts = preg_split('/\s+/', trim((string) $contact->getName()), 2);
            $firstName = $parts[0] ?? 'N/A';
            $lastName = $parts[1] ?? $firstName;
        }

        $payload = [
            'firstName' => $firstName !== '' ? $firstName : 'N/A',
            'lastName' => $lastName !== '' ? $lastName : 'N/A',
            'email' => (string) $contact->getEmail(),
            'address1' => (string) $contact->getAddress1(),
            'city' => (string) $contact->getCity(),
            'country' => strtoupper((string) $contact->getCountry()),
            'phone' => $this->buildEppPhone($contact->getTelCc(), $contact->getTel()),
        ];

        if ($contact->getCompany()) {
            $payload['organization'] = $contact->getCompany();
        }
        if ($contact->getAddress2()) {
            $payload['address2'] = $contact->getAddress2();
        }
        if ($contact->getState()) {
            $payload['stateProvince'] = $contact->getState();
        }
        if ($contact->getZip()) {
            $payload['postalCode'] = $contact->getZip();
        }
        if (method_exists($contact, 'getDocumentNr') && $contact->getDocumentNr()) {
            $payload['taxNumber'] = $contact->getDocumentNr();
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // DNS records
    // ------------------------------------------------------------------

    public function saveDnsRecords(string $domainName, array $records, bool $force = false): bool
    {
        [$status, $decoded, ] = $this->rawRequest('PUT', '/dns/records/' . rawurlencode($domainName), [
            'force' => $force,
            'items' => array_values($records),
        ]);
        $this->assertSuccess($status, $decoded);

        return true;
    }

    public function deleteDnsRecords(string $domainName, array $records): bool
    {
        [$status, $decoded, ] = $this->rawRequest('DELETE', '/dns/records/' . rawurlencode($domainName), array_values($records));
        $this->assertSuccess($status, $decoded);

        return true;
    }

    public function getDnsRecords(string $domainName, int $take = 100, int $skip = 0): array
    {
        $response = $this->apiRequest('GET', '/dns/records/' . rawurlencode($domainName) . '?' . http_build_query([
            'take' => $take,
            'skip' => $skip,
        ]));

        return $response['items'] ?? [];
    }

    // ------------------------------------------------------------------
    // Async operations
    // ------------------------------------------------------------------

    public function getAsyncOperation(string $operationId): array
    {
        return $this->apiRequest('GET', '/async-operations/' . rawurlencode($operationId));
    }

    private function waitForAsyncOperation(string $operationId): array
    {
        $deadline = time() + self::ASYNC_TIMEOUT;

        do {
            $operation = $this->getAsyncOperation($operationId);
            $status = $operation['status'] ?? 'pending';

            if ($status !== 'pending') {
                return $operation;
            }

            if (time() >= $deadline) {
                throw new Registrar_Exception(sprintf(
                    'Timed out waiting for Spaceship operation %s to finish; check its status later via getAsyncOperation()',
                    $operationId
                ));
            }

            sleep(self::ASYNC_POLL_INTERVAL);
        } while (true);
    }

    // ------------------------------------------------------------------
    // HTTP plumbing
    // ------------------------------------------------------------------

    private function apiRequest(string $method, string $path, ?array $body = null): array
    {
        [$status, $decoded, ] = $this->rawRequest($method, $path, $body);
        $this->assertSuccess($status, $decoded);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: int, 1: mixed, 2: ?string} [status, decoded body, async-operation-id header] */
    private function rawRequest(string $method, string $path, ?array $body = null): array
    {
        $client = $this->getHttpClient()->withOptions([
            'timeout' => 30,
            'headers' => [
                'X-Api-Key' => $this->config['api_key'],
                'X-Api-Secret' => $this->config['api_secret'],
                'Accept' => 'application/json',
            ],
        ]);

        $options = [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $client->request($method, self::API_BASE . $path, $options);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $this->getLog()->error('Spaceship HTTP transport error: ' . $e->getMessage());
            throw new Registrar_Exception('Failed to communicate with the Spaceship API: ' . $e->getMessage());
        }

        $decoded = $content !== '' ? json_decode($content, true) : null;
        $operationId = $headers['spaceship-async-operationid'][0] ?? null;

        return [$status, $decoded, $operationId];
    }

    private function assertSuccess(int $status, $decoded): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['detail'] ?? null) : null;
        $message ??= 'HTTP ' . $status;

        $this->getLog()->error('Spaceship API error (' . $status . '): ' . $message);

        throw new Registrar_Exception('Failed to complete the request with the Spaceship registrar, check the error logs for further details: ' . $message);
    }

    // ------------------------------------------------------------------
    // Formatting helpers
    // ------------------------------------------------------------------

    private function normalizeYears(?int $years): int
    {
        return max(1, min(10, $years ?? 1));
    }

    private function toIso8601UtcDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = (is_int($value) || ctype_digit((string) $value))
            ? (int) $value
            : strtotime((string) $value);

        if ($timestamp === false) {
            return (string) $value;
        }

        return gmdate('Y-m-d\TH:i:s.000\Z', $timestamp);
    }

    private function buildEppPhone($telCc, $tel): string
    {
        $cc = preg_replace('/[^0-9]/', '', (string) $telCc);
        $number = preg_replace('/[^0-9]/', '', (string) $tel);

        if ($cc === '') {
            return $number;
        }

        return '+' . $cc . '.' . $number;
    }

    private function splitEppPhone(string $phone): array
    {
        if (preg_match('/^\+(\d{1,3})\.(\d+)$/', $phone, $m)) {
            return [$m[1], $m[2]];
        }

        return ['', preg_replace('/[^0-9]/', '', $phone)];
    }
}
