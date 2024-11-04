<?php

namespace Drupal\bo_system\Drush\Commands;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drush\Commands\DrushCommands;
use Drupal\taxonomy\Entity\Term;

/**
 * BoSystemDrushCommands provides the Drush hook implementation for cache clears.
 */
class BoSystemDrushCommands extends DrushCommands {

  /**
   * The module_handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * TokenCommands constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module_handler service.
   */
  public function __construct(ModuleHandlerInterface $moduleHandler) {
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('module_handler')
    );
  }

  /**
   * Import taxonomy terms from a JSON file located in the module.
   *
   * @command location-terms:import
   * @aliases lt-import
   */
  public function importTerms() {
    $file_name = 'locations.json';
    
    // Costruisci il percorso completo del file.
    $module_path = \Drupal::service('extension.path.resolver')->getPath('module', 'bo_system');
    $file_path = $module_path . '/assets/' . $file_name;

    // Verifica che il file esista.
    if (!file_exists($file_path)) {
      $this->logger()->error(dt('Il file @file_path non esiste.', ['@file_path' => $file_path]));
      return;
    }

    // Leggi il contenuto del file JSON.
    $json_data = file_get_contents($file_path);
    $terms = json_decode($json_data, TRUE);

    // Verifica che il JSON sia valido.
    if ($terms === NULL) {
      $this->logger()->error(dt('Il file JSON non può essere letto o contiene errori.'));
      return;
    }

    // Array per mappare i termini appena creati in base al field_sorting_tid.
    $created_terms = [];

    // Step 1: Importa i termini tassonomici senza impostare il parent.
    foreach ($terms as $term_data) {
      // Crea il termine tassonomico.
      $term = Term::create([
        'vid' => 'location', // Sostituisci con la machine name del tuo vocabolario.
        'name' => $term_data['name'],
        'field_sorting_tid' => $term_data['tid'],
      ]);

      // Salva il termine tassonomico.
      $term->save();

      // Salva il termine nell'array associativo.
      $created_terms[$term_data['tid']] = $term;

      // Output per il monitoraggio.
      $this->logger()->success(dt('Creato il termine: @name con field_sorting_tid: @tid', [
        '@name' => $term_data['name'],
        '@tid' => $term_data['tid']
      ]));
    }

    // Step 2: Aggiorna i termini tassonomici per impostare il campo "parent".
    foreach ($terms as $term_data) {
      if ($term_data['parent_term'] != "0" && isset($created_terms[$term_data['parent_term']])) {
        $term = $created_terms[$term_data['tid']];
        $parent_term = $created_terms[$term_data['parent_term']];

        // Imposta il parent.
        $term->parent = [$parent_term->id()];
        $term->save();

        // Output per il monitoraggio.
        $this->logger()->success(dt('Aggiornato il termine: @name con parent: @parent_name', [
          '@name' => $term_data['name'],
          '@parent_name' => $parent_term->getName(),
        ]));
      }
    }
    
    // Step 3: Rimuovi il secondo livello e associa i termini di terzo livello al primo livello.
    $this->adjustHierarchy();

    $custom_locations = [
      'Chianti', 'Maremma', 'Salento', 'Lake Como', 'Cilento', 'Lake Garda'
    ];
    foreach ($custom_locations as $cl) {
      $term = Term::create([
        'vid' => 'location', // Sostituisci con la machine name del tuo vocabolario.
        'name' => $term_data['name'],
        'field_sorting_tid' => $term_data['tid'],
      ]);
    }
  }

  /**
   * Regola la gerarchia rimuovendo il secondo livello e associando i termini di terzo livello al primo.
   */
  protected function adjustHierarchy() {
    // Carica tutti i termini del vocabolario "location".
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('location', 0, NULL, TRUE);
  
    // Mantieni una lista di termini "Province" da eliminare dopo aver aggiornato i figli.
    $province_terms_to_delete = [];
  
    // Loop attraverso tutti i termini.
    foreach ($terms as $term) {
      // Carica il termine completo per accedere ai suoi campi.
      $loaded_term = Term::load($term->id());
  
      // Controlla se il termine contiene "Province" nel nome.
      if (strpos($loaded_term->getName(), 'Province') !== FALSE) {
        // Aggiungi il termine "Province" alla lista per l'eliminazione.
        $province_terms_to_delete[] = $loaded_term;
  
        // Trova il termine genitore (livello superiore).
        if ($loaded_term->parent->target_id) {
          $parent_term = Term::load($loaded_term->parent->target_id);
  
          if ($parent_term) {
            // Trova i figli del termine "Province" (livello inferiore).
            $children = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['parent' => $loaded_term->id()]);
  
            // Associa ogni figlio al genitore del termine "Province".
            foreach ($children as $child) {
              $child->parent = [$parent_term->id()];
              $child->save();
  
              $this->logger()->success(dt('Spostato il termine @child_name sotto il genitore @parent_name', [
                '@child_name' => $child->getName(),
                '@parent_name' => $parent_term->getName(),
              ]));
            }
          }
        }
      }
    }
  
    // Ora elimina i termini "Province" dopo aver spostato i figli.
    foreach ($province_terms_to_delete as $term_to_delete) {
      $term_to_delete->delete();
      $this->logger()->success(dt('Eliminato il termine "Province": @name', ['@name' => $term_to_delete->getName()]));
    }
  }
  

}
