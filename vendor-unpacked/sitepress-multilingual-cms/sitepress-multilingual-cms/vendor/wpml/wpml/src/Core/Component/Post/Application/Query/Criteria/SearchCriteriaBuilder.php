<?php

namespace WPML\Core\Component\Post\Application\Query\Criteria;

use WPML\Core\SharedKernel\Component\Language\Application\Query\LanguagesQueryInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;
use WPML\PHP\Value\Validate;

final class SearchCriteriaBuilder {

  private $languagesBuilder;


  public function __construct( LanguagesQueryInterface $languagesQuery ) {
    $this->languagesBuilder = new SourceAndTargetLanguagesBuilder( $languagesQuery );
  }


  public function build( array $array ): SearchCriteria {
    return new SearchCriteria(
      Validate::nonEmptyString( [ $array, 'type' ] ),
      $this->buildLanguages( $array ),
      Validate::nonEmptyString( [ $array, 'title' ], null ),
      Validate::nonEmptyString( [ $array, 'publicationStatus' ], null ),
      Validate::arrayOfSameType(
        [ $array, 'translationStatuses' ],
        [ Validate::class, 'int' ],
        []
      ),
      Validate::int( [ $array, 'parentId' ], null ),
      Validate::nonEmptyString( [ $array, 'taxonomyId' ], null ),
      Validate::int( [ $array, 'termId' ], null ),
      Validate::int( [ $array, 'limit' ], 10 ),
      Validate::int( [ $array, 'offset' ], 0 ),
      Validate::array(
        [ $array, 'sorting' ],
        [
          'by' => [ Validate::class, 'nonEmptyString' ],
          'order' => [ Validate::class, 'nonEmptyString' ]
        ],
        null
      )
    );
  }


  private function buildLanguages( array $array ): SourceAndTargetLanguages {
    $targetLang = Validate::nonEmptyString( [ $array, 'targetLanguageCode' ], null );
    $languages = $this->languagesBuilder->build(
      Validate::nonEmptyString( [ $array, 'sourceLanguageCode' ], null ),
      $targetLang ? [ $targetLang ] : []
    );

    return $languages;
  }


}
