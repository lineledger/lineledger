<?php

namespace App\Support\Mcp;

use App\Models\Contact;

/**
 * A customer's or vendor's contact details for the MCP tools: names, roles,
 * email, phones, billing and shipping addresses, terms and currency, with the
 * numeric API id.
 *
 * Deliberately left out: the tax number (for a US vendor it may be a
 * person's SSN-type TIN), internal notes, and anything portal-related.
 */
final class ContactCardPresenter
{
    /**
     * The relations toArray()/toText() read; eager-load them with the contact.
     *
     * @var list<string>
     */
    public const RELATIONS = ['defaultTerms', 'parent'];

    /**
     * @return array{
     *   id: int, name: string, company_name: ?string, roles: list<string>, email: ?string,
     *   phone: ?string, mobile: ?string, billing_address: list<string>, shipping_address: list<string>,
     *   terms: ?string, currency: ?string, parent: ?string, active: bool,
     * }
     */
    public static function toArray(Contact $contact): array
    {
        $billing = self::address($contact, 'billing');
        $shipping = self::address($contact, 'shipping');

        return [
            'id' => $contact->id,
            'name' => $contact->display_name,
            'company_name' => self::blankToNull($contact->company_name),
            'roles' => self::roles($contact),
            'email' => self::blankToNull($contact->email),
            'phone' => self::blankToNull($contact->phone),
            'mobile' => self::blankToNull($contact->mobile),
            'billing_address' => $billing,
            'shipping_address' => $shipping === $billing ? [] : $shipping,
            'terms' => $contact->defaultTerms?->name,
            'currency' => self::blankToNull($contact->currency_code),
            'parent' => $contact->parent?->display_name,
            'active' => (bool) $contact->is_active,
        ];
    }

    /**
     * A multi-line text block. $heading prefixes the name ("Bill to").
     */
    public static function toText(Contact $contact, ?string $heading = null): string
    {
        $card = self::toArray($contact);

        $out = ($heading !== null ? "{$heading}: " : '').$card['name']." (API id {$card['id']})";

        if ($card['company_name'] !== null && $card['company_name'] !== $card['name']) {
            $out .= "\n  Company: {$card['company_name']}";
        }

        if ($card['parent'] !== null) {
            $out .= "\n  Sub-customer of: {$card['parent']}";
        }

        $out .= "\n  Billing address: ".($card['billing_address'] === [] ? 'none on file' : implode(', ', $card['billing_address']));

        if ($card['shipping_address'] !== []) {
            $out .= "\n  Shipping address: ".implode(', ', $card['shipping_address']);
        }

        foreach (['email' => 'Email', 'phone' => 'Phone', 'mobile' => 'Mobile', 'terms' => 'Terms', 'currency' => 'Currency'] as $key => $label) {
            if ($card[$key] !== null) {
                $out .= "\n  {$label}: {$card[$key]}";
            }
        }

        if (! $card['active']) {
            $out .= "\n  (inactive)";
        }

        return $out;
    }

    /**
     * Address lines: street lines, then "City, Region  Postal", then country.
     *
     * @param  'billing'|'shipping'  $kind
     * @return list<string>
     */
    public static function address(Contact $contact, string $kind): array
    {
        $street = array_filter([
            self::blankToNull($contact->{"{$kind}_line1"}),
            self::blankToNull($contact->{"{$kind}_line2"}),
        ]);

        $city = self::blankToNull($contact->{"{$kind}_city"});
        $region = self::blankToNull($contact->{"{$kind}_region"});
        $postal = self::blankToNull($contact->{"{$kind}_postal_code"});

        $locality = trim(implode(', ', array_filter([$city, $region])).($postal !== null ? "  {$postal}" : ''));

        return array_values(array_filter([
            ...$street,
            $locality !== '' ? $locality : null,
            self::blankToNull($contact->{"{$kind}_country"}),
        ]));
    }

    /**
     * @return list<string>
     */
    private static function roles(Contact $contact): array
    {
        return array_keys(array_filter([
            'customer' => (bool) $contact->is_customer,
            'vendor' => (bool) $contact->is_vendor,
            'other name' => (bool) $contact->is_other_name,
        ]));
    }

    private static function blankToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
