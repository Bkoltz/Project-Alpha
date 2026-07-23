<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * Canonical contract for Workforce mutations shared by HTML and JSON entry
 * points. Policy keys are resolved by the application service/controller; the
 * registry prevents UI controls and endpoints from inventing divergent action
 * names or HTTP methods.
 */
final class WorkforceCommandRegistry
{
    /** @return array<string,array{method:string,policy:string,csrf:bool}> */
    public static function definitions(): array
    {
        return [
            'save-entry' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'clock-in' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'clock-out' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'break-start' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'break-end' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'manual-create' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'quick-duration' => ['method' => 'POST', 'policy' => 'time.capture', 'csrf' => true],
            'edit' => ['method' => 'POST', 'policy' => 'time.edit', 'csrf' => true],
            'resubmit' => ['method' => 'POST', 'policy' => 'time.edit', 'csrf' => true],
            'cancel' => ['method' => 'POST', 'policy' => 'time.edit', 'csrf' => true],
            'submit-period' => ['method' => 'POST', 'policy' => 'time.submit', 'csrf' => true],
            'approve' => ['method' => 'POST', 'policy' => 'time.review', 'csrf' => true],
            'reject' => ['method' => 'POST', 'policy' => 'time.review', 'csrf' => true],
            'return-entry' => ['method' => 'POST', 'policy' => 'time.review', 'csrf' => true],
            'void' => ['method' => 'POST', 'policy' => 'time.void', 'csrf' => true],
            'correction-request' => ['method' => 'POST', 'policy' => 'time.correction_request', 'csrf' => true],
            'admin-correction-apply' => ['method' => 'POST', 'policy' => 'corrections.manage', 'csrf' => true],
            'correction-approve' => ['method' => 'POST', 'policy' => 'corrections.manage', 'csrf' => true],
            'correction-reject' => ['method' => 'POST', 'policy' => 'corrections.manage', 'csrf' => true],
            'correction-billing-resolve' => ['method' => 'POST', 'policy' => 'time.billing', 'csrf' => true],
            'link-invoice' => ['method' => 'POST', 'policy' => 'time.billing', 'csrf' => true],
            'assignment-accept' => ['method' => 'POST', 'policy' => 'assignment.self', 'csrf' => true],
            'assignment-decline' => ['method' => 'POST', 'policy' => 'assignment.self', 'csrf' => true],
            'assignment-start' => ['method' => 'POST', 'policy' => 'assignment.self', 'csrf' => true],
            'assignment-complete' => ['method' => 'POST', 'policy' => 'assignment.self', 'csrf' => true],
            'earning-approve' => ['method' => 'POST', 'policy' => 'earnings.manage', 'csrf' => true],
            'pay-status' => ['method' => 'POST', 'policy' => 'earnings.legacy_manage', 'csrf' => true],
            'statement-settle' => ['method' => 'POST', 'policy' => 'statements.manage', 'csrf' => true],
            'worker-payment-record' => ['method' => 'POST', 'policy' => 'payments.manage', 'csrf' => true],
            'worker-payment-void' => ['method' => 'POST', 'policy' => 'payments.manage', 'csrf' => true],
            'payroll-export-generate' => ['method' => 'POST', 'policy' => 'payroll_exports.manage', 'csrf' => true],
            'payroll-export-void' => ['method' => 'POST', 'policy' => 'payroll_exports.manage', 'csrf' => true],
        ];
    }

    /** @return array{method:string,policy:string,csrf:bool} */
    public static function require(string $action, string $method = 'POST'): array
    {
        $definition = self::definitions()[$action] ?? null;
        if ($definition === null) {
            throw new DomainException('Unsupported workforce action.');
        }
        if (strtoupper($method) !== $definition['method']) {
            throw new DomainException('The workforce action used an invalid HTTP method.');
        }
        return $definition;
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::definitions());
    }
}
