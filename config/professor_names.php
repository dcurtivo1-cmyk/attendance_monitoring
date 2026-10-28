<?php
/** Display a professor's actual name without leading honorifics or role titles. */
function professor_display_name(?string $name): string
{
    $name = trim($name ?? '');
    if (preg_match('/^(?:Assigned\s+)?(?:Professor|Teacher)$/i', $name)) {
        return 'Unassigned';
    }
    $name = preg_replace("/^(?:(?:Professor|Prof|Teacher|Mr|Mrs|Ms|Miss|Dr|Sir|Ma['\x{2019}]?am)\\.?\\s+)+/iu", '', $name);
    return trim($name) !== '' ? trim($name) : 'Unassigned';
}
