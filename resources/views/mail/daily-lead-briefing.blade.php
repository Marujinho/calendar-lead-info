{{-- Provider data is echoed with {{ }} only, after $cell() flattened it to one line. See App\Mail\DailyLeadBriefing. --}}
<x-mail::message>
# Your lead meetings for {{ $digest->localDate->format('l, j F Y') }}

Hi {{ $cell($rep->name) }}, here are the leads you are meeting today. Times are in {{ $digest->timezone }}.

@if ($refreshedAtLocal !== null)
<x-mail::panel>
Meeting data last refreshed at {{ $refreshedAtLocal }}. Meetings booked or changed since then are not shown.
</x-mail::panel>
@endif

@foreach ($digest->meetings as $meeting)
## {{ $meeting->startAtLocal->format('H:i') }}–{{ $meeting->endAtLocal->format('H:i') }} · {{ $cell($meeting->title) }}

<x-mail::table>
| Name | Company | Email | LinkedIn | Meetings with us (30 d) |
| :--- | :--- | :--- | :--- | ---: |
@foreach ($meeting->leads as $lead)
@php($linkedin = \App\Services\Digest\LinkedInUrl::safe($lead->linkedinUrl))
| {{ $cell($lead->fullName) }} | {{ $cell($lead->companyName) }} | {{ $cell($lead->email) }} | @if ($linkedin === null)—@elseif ($isHtml)[{{ $cell($linkedin) }}](<{{ $linkedin }}>)@else{{ $linkedin }}@endif | {{ $lead->meetingsLast30Days }} |
@endforeach
</x-mail::table>

@endforeach
</x-mail::message>
