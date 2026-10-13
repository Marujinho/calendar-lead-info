<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Data\AttendeeData;
use App\Data\Enums\ResponseStatus;
use App\Services\Sync\LeadClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeadClassifierTest extends TestCase
{
    private LeadClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new LeadClassifier(['OurCo.com', ' ourco.io '], ['C@Gmail.com']);
    }

    #[Test]
    public function the_prd_example_yields_only_external_non_rep_attendees(): void
    {
        self::assertFalse($this->classifier->isLead($this->attendee('a@ourco.com')));
        self::assertFalse($this->classifier->isLead($this->attendee('c@gmail.com')));
        self::assertTrue($this->classifier->isLead($this->attendee('b@client.com')));
    }

    #[Test]
    public function every_internal_domain_is_excluded(): void
    {
        self::assertFalse($this->classifier->isLead($this->attendee('x@ourco.io')));
    }

    #[Test]
    public function a_rep_on_an_external_domain_is_not_a_lead(): void
    {
        self::assertFalse($this->classifier->isLead($this->attendee('c@gmail.com')));
        self::assertTrue($this->classifier->isLead($this->attendee('someone.else@gmail.com')));
    }

    #[Test]
    public function matching_is_case_insensitive_on_both_sides(): void
    {
        self::assertFalse($this->classifier->isLead($this->attendee('A@OURCO.COM')));
        self::assertFalse($this->classifier->isLead($this->attendee(' c@GMAIL.com ')));
        self::assertTrue($this->classifier->isLead($this->attendee('B@Client.COM')));
    }

    #[Test]
    public function a_subdomain_of_an_internal_domain_is_external(): void
    {
        self::assertTrue($this->classifier->isLead($this->attendee('x@eu.ourco.com')));
    }

    #[Test]
    public function a_declined_external_attendee_is_still_a_lead_for_storage(): void
    {
        self::assertTrue($this->classifier->isLead($this->attendee('d@client.com', ResponseStatus::Declined)));
    }

    #[Test]
    public function an_address_without_a_domain_is_never_a_lead(): void
    {
        self::assertFalse($this->classifier->isLead($this->attendee('not-an-email')));
        self::assertFalse($this->classifier->isLead($this->attendee('trailing@')));
    }

    #[Test]
    public function with_no_internal_domains_or_reps_every_valid_address_is_a_lead(): void
    {
        $classifier = new LeadClassifier([], []);

        self::assertTrue($classifier->isLead($this->attendee('a@ourco.com')));
    }

    private function attendee(string $email, ResponseStatus $status = ResponseStatus::Accepted): AttendeeData
    {
        return new AttendeeData($email, 'Someone', responseStatus: $status);
    }
}
