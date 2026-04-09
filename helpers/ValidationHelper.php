<?php

class ValidationHelper
{
  /**
   * Check multiple rules at once and return all errors
   *
   * Usage:
   *   $errors = ValidationHelper::check($input, [
   *       'name'     => 'required|min:2|max:150',
   *       'email'    => 'required|email',
   *       'password' => 'required|min:8',
   *       'age'      => 'numeric|min:18',
   *   ]);
   *
   *   if (!empty($errors)) {
   *       Response::validationError($errors);
   *   }
   */
  public static function check(array $data, array $rules): array
  {
    $errors = [];

    foreach ($rules as $field => $ruleString) {
      $value    = $data[$field] ?? null;
      $ruleList = explode('|', $ruleString);

      foreach ($ruleList as $rule) {
        $error = self::applyRule($field, $value, $rule, $data);
        if ($error) {
          $errors[$field] = $error;
          break; // stop at first error per field
        }
      }
    }

    return $errors;
  }

  /**
   * Apply a single rule to a value
   * Returns an error string or null if valid
   */
  private static function applyRule(string $field, mixed $value, string $rule, array $data): ?string
  {
    $label = self::label($field);

    // Rules with parameters like min:8, max:150
    if (str_contains($rule, ':')) {
      [$ruleName, $param] = explode(':', $rule, 2);
    } else {
      $ruleName = $rule;
      $param    = null;
    }

    return match ($ruleName) {

      // Field must be present and not empty
      'required' => (
        $value === null || trim((string) $value) === ''
        ? "{$label} is required."
        : null
      ),

      // Must be a valid email address
      'email' => (
        !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)
        ? "{$label} must be a valid email address."
        : null
      ),

      // Minimum string length
      'min' => (
        !empty($value) && strlen((string) $value) < (int) $param
        ? "{$label} must be at least {$param} characters."
        : null
      ),

      // Maximum string length
      'max' => (
        !empty($value) && strlen((string) $value) > (int) $param
        ? "{$label} must not exceed {$param} characters."
        : null
      ),

      // Must be numeric
      'numeric' => (
        !empty($value) && !is_numeric($value)
        ? "{$label} must be a number."
        : null
      ),

      // Minimum numeric value
      'min_val' => (
        !empty($value) && (float) $value < (float) $param
        ? "{$label} must be at least {$param}."
        : null
      ),

      // Maximum numeric value
      'max_val' => (
        !empty($value) && (float) $value > (float) $param
        ? "{$label} must not exceed {$param}."
        : null
      ),

      // Must match another field — e.g. confirm:password
      'confirm' => (
        !empty($value) && $value !== ($data[$param] ?? null)
        ? "{$label} does not match {$param}."
        : null
      ),

      // Must be one of a list — e.g. in:draft,published,cancelled
      'in' => (
        !empty($value) && !in_array($value, explode(',', $param), true)
        ? "{$label} must be one of: {$param}."
        : null
      ),

      // Must be a valid date string
      'date' => (
        !empty($value) && strtotime($value) === false
        ? "{$label} must be a valid date."
        : null
      ),

      // Must be a valid URL
      'url' => (
        !empty($value) && !filter_var($value, FILTER_VALIDATE_URL)
        ? "{$label} must be a valid URL."
        : null
      ),

      // Unknown rule — skip it silently
      default => null,
    };
  }

  /**
   * Convert snake_case field names to readable labels
   * e.g. ticket_type_id → Ticket type id
   */
  private static function label(string $field): string
  {
    return ucfirst(str_replace('_', ' ', $field));
  }
}
