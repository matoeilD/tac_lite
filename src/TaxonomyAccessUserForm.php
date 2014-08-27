<?php

/**
 * @file
 * Contains \Drupal\tac_lite\TaxonomyAccessUserForm.
 */

namespace Drupal\tac_lite;

use Drupal\Core\Form\FormBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TaxonomyAccessUserForm extends FormBase {

  /**
   * @var \Drupal\user\Entity\User
   */
  protected $account;

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'tac_lite_user';
  }

  public function __construct($account) {
    $this->account = $account;
  }

  public static function create(ContainerInterface $container) {
    /**
     * @var \Symfony\Component\HttpFoundation\Request $request;
     */
    $request = $container->get('request');

    return new static(
      $request->attributes->get('user')
    );
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param array $form_state
   *   An associative array containing the current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, array &$form_state) {
    $permissions = $this->getPermissions($this->account);

    $form['help'] = array(
      '#markup' => '<p>' . t('You may grant this user access based on the schemes and terms below. These permissions are in addition to <a href="!url">role based grants on scheme settings pages</a>.',
          array('!url' => url('admin/config/people/tac_lite/scheme_1'))) . "</p>\n",
    );

    $bundles = Vocabulary::loadMultiple($this->config('tac_lite.settings')->get('vocabularies'));
    $schemes = tac_lite_load_schemes();

    $form['permissions'] = array(
      '#type' => 'table',
      '#header' => array($this->t('Term')),
      '#id' => 'permissions',
      '#sticky' => TRUE,
    );

    foreach ($schemes as $scheme) {
      $form['permissions']['#header'][$scheme->id()] = $scheme->label();
    }

    foreach ($bundles as $bundle) {
      foreach (taxonomy_get_tree($bundle->id()) as $term) {
        $form['permissions'][$term->tid][] = array(
          '#markup' => $term->name
        );

        foreach ($schemes as $scheme) {
          $form['permissions'][$term->tid][] = array(
            '#type' => 'checkbox',
            '#title_display' => 'invisible',
            '#parents' => array('permissions', $scheme->id(), $term->tid),
            '#default_value' => isset($permissions[$scheme->id()][$term->tid]) ? TRUE : FALSE,
          );
        }
      }
    }

    $form['actions'] = array(
      '#type' => 'actions',
      'submit' => array(
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
      ),
    );

    return $form;
  }

  /**
   * Helper function to build a taxonomy term select element for a form.
   *
   * @param $v
   *   A vocabulary object containing a vid and name.
   * @param $default_values
   *   An array of values to use for the default_value argument for this form element.
   */
  protected function term_select($v, $default_values = array()) {
    $tree = taxonomy_get_tree($v->id());
    $options = array(0 => '<' . t('none') . '>');
    if ($tree) {
      foreach ($tree as $term) {
        $choice = new \stdClass();
        $choice->option = array($term->tid => str_repeat('-', $term->depth) . $term->name);
        $options[] = $choice;
      }
    }
    $field_array = array(
      '#type' => 'select',
      '#title' => $v->name,
      '#default_value' => $default_values,
      '#options' => $options,
      '#multiple' => TRUE,
      '#description' => $v->description,
    );
    return $field_array;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param array $form_state
   *   An associative array containing the current state of the form.
   */
  public function submitForm(array &$form, array &$form_state) {
    foreach ($form_state['values']['permissions'] as $scheme => $terms) {
      foreach ($terms as $tid => $value) {
        if (empty($value)) {
          $this->revokePermission($scheme, $this->account->id(), $tid);
        }
        else {
          $this->grantPermission($scheme, $this->account->id(), $tid);
        }
      }
    }
  }

  protected function grantPermission($scheme, $uid, $tid) {
    return db_merge('tac_lite_user')
      ->keys(array('scheme' => $scheme, 'uid' => $uid, 'tid' => $tid))
      ->fields(array('scheme' => $scheme, 'uid' => $uid, 'tid' => $tid))
      ->execute();
  }

  protected function revokePermission($scheme, $uid, $tid) {
    return db_delete('tac_lite_user')
      ->condition('scheme', $scheme)
      ->condition('uid', $uid)
      ->condition('tid', $tid)
      ->execute();
  }

  public function getPermissions($account) {
    $permissions = array();
    $values = db_select('tac_lite_user', 'tlu')
      ->fields('tlu', array('scheme', 'tid'))
      ->condition('uid', $account->id())
      ->execute()
      ->fetchAll();

    if (empty($values)) {
      return $permissions;
    }

    foreach ($values as $value) {
      $permissions[$value->scheme][$value->tid] = TRUE;
    }

    return $permissions;
  }

}