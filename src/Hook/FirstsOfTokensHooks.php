<?php

namespace Drupal\firsts_of\Hook;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for user.
 */
class FirstsOfTokensHooks {

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo() {
    $types['node'] = [
      'name' => t('Nodes'),
      'description' => t('Tokens related to individual content items, or "nodes".'),
      'needs-data' => 'node',
    ];

    $node['firstof'] = [
      'name' => t("First of"),
      'description' => t("The first paragraph of a given type."),
      'type' => 'string',
    ];

    return ['types' => $types, 'tokens' => ['node' => $node]];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(
    $type,
    $tokens,
    array $data,
    array $options,
    BubbleableMetadata $bubbleable_metadata,
  ) {
    $token_service = \Drupal::token();

    $replacements = [];

    if ($type === 'node') {
      if ($firstof_tokens = $token_service->findWithPrefix($tokens, 'firstof')) {
        foreach ($firstof_tokens as $name => $original) {
          $type_view_field = '/^([a-z0-9_]+)\+([a-z0-9_]+)@([a-z0-9_]+)$/';
          if (preg_match($type_view_field, $name, $parts) !== 1) {
            continue;
          }

          $paragraph_type = $parts[1];
          $paragraph_view = $parts[2];
          $paragraph_field = $parts[3];

          $node = $data['node'] ?? NULL;

          if (!$node
           || !$node->hasField($paragraph_field)
           || $node->get($paragraph_field)->isEmpty()
          ) {
            $replacements[$original] = $paragraph_field;
            continue;
          }

          $paragraphs = $node->get($paragraph_field)->referencedEntities();

          $first_matching_paragraph = NULL;
          foreach ($paragraphs as $paragraph) {
            if ($paragraph->bundle() === $paragraph_type) {
              $first_matching_paragraph = $paragraph;
              break;
            }
          }

          if ($first_matching_paragraph) {
            $build = \Drupal::entityTypeManager()
              ->getViewBuilder('paragraph')
              ->view($first_matching_paragraph, $paragraph_view);

            $replacements[$original] = trim(
              \Drupal::service('renderer')->render($build)
            );

            $bubbleable_metadata
              ->addCacheableDependency($first_matching_paragraph);
          }
          else {
            $replacements[$original] = '';
          }
        }
      }
    }

    return $replacements;
  }

}
