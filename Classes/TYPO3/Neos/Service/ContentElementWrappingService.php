<?php
namespace TYPO3\Neos\Service;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.Neos".            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\Flow\Security\Authorization\PrivilegeManagerInterface;
use TYPO3\Media\TypeConverter\ImageInterfaceJsonSerializer;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Service\AuthorizationService;

/**
 * The content element wrapping service adds the necessary markup around
 * a content element such that it can be edited using the Content Module
 * of the Neos Backend.
 *
 * @Flow\Scope("singleton")
 */
class ContentElementWrappingService {

	/**
	 * @Flow\Inject
	 * @var ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @Flow\Inject
	 * @var PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @Flow\Inject
	 * @var PrivilegeManagerInterface
	 */
	protected $privilegeManager;

	/**
	 * @Flow\Inject
	 * @var AuthorizationService
	 */
	protected $nodeAuthorizationService;

	/**
	 * @Flow\Inject
	 * @var HtmlAugmenter
	 */
	protected $htmlAugmenter;

	/**
	 * @Flow\Inject
	 * @var ImageInterfaceJsonSerializer
	 */
	protected $imageInterfaceJsonSerializer;

	/**
	 * Wrap the $content identified by $node with the needed markup for the backend.
	 *
	 * @param NodeInterface $node
	 * @param string $typoScriptPath
	 * @param string $content
	 * @param boolean $renderCurrentDocumentMetadata When this flag is set we will render the global metadata for the current document
	 * @return string
	 */
	public function wrapContentObject(NodeInterface $node, $typoScriptPath, $content, $renderCurrentDocumentMetadata = FALSE) {
		/** @var $contentContext ContentContext */
		$contentContext = $node->getContext();
		if ($contentContext->getWorkspaceName() === 'live' || !$this->privilegeManager->isPrivilegeTargetGranted('TYPO3.Neos:Backend.GeneralAccess')) {
			return $content;
		}
		$nodeType = $node->getNodeType();
		$attributes = array();
		$attributes['typeof'] = 'typo3:' . $nodeType->getName();
		$attributes['about'] = $node->getContextPath();

		$classNames = array();
		if ($renderCurrentDocumentMetadata === TRUE) {
			$attributes['data-neos-site-name'] = $contentContext->getCurrentSite()->getName();
			$attributes['data-neos-site-node-context-path'] = $contentContext->getCurrentSiteNode()->getContextPath();
			// Add the workspace of the TYPO3CR context to the attributes
			$attributes['data-neos-context-workspace-name'] = $contentContext->getWorkspaceName();
			$attributes['data-neos-context-dimensions'] = json_encode($contentContext->getDimensions());

			if (!$this->nodeAuthorizationService->isGrantedToEditNode($node)) {
				$attributes['data-node-__read-only'] = 'true';
				$attributes['data-nodedatatype-__read-only'] = 'boolean';
			}

		} else {

			if (!$this->nodeAuthorizationService->isGrantedToEditNode($node)) {
				return $content;
			}


			if ($node->isRemoved()) {
				if ($node instanceof \TYPO3\TYPO3CR\Domain\Model\Node && $node->isShadowNode()) {
					return '';
				}

				$classNames[] = 'neos-contentelement-removed';
			}

			if ($node->isHidden()) {
				$classNames[] = 'neos-contentelement-hidden';
			}

			if ($nodeType->isOfType('TYPO3.Neos:ContentCollection')) {
				$attributes['rel'] = 'typo3:content-collection';
			} else {
				$classNames[] = 'neos-contentelement';
			}

			$uiConfiguration = $nodeType->hasConfiguration('ui') ? $nodeType->getConfiguration('ui') : array();
			if ((isset($uiConfiguration['inlineEditable']) && $uiConfiguration['inlineEditable'] !== TRUE) || (!isset($uiConfiguration['inlineEditable']) && !$this->hasInlineEditableProperties($node))) {
				$classNames[] = 'neos-not-inline-editable';
			}

			$attributes['tabindex'] = 0;
		}

		if (!$node->dimensionsAreMatchingTargetDimensionValues()) {
			$classNames[] = 'neos-contentelement-shine-through';
		}

		if (count($classNames) > 0) {
			$attributes['class'] = implode(' ', $classNames);
		}

		// Add the actual workspace of the node, the node identifier and the TypoScript path to the attributes
		$attributes['data-node-_identifier'] = $node->getIdentifier();
		$attributes['data-node-__workspace-name'] = $node->getWorkspace()->getName();
		$attributes['data-node-__typoscript-path'] = $typoScriptPath;

		// these properties are needed together with the current NodeType to evaluate Node Type Constraints
		// TODO: this can probably be greatly cleaned up once we do not use CreateJS or VIE anymore.
		if ($node->getParent()) {
			$attributes['data-node-__parent-node-type'] = $node->getParent()->getNodeType()->getName();
		}

		if ($node->isAutoCreated()) {
			$attributes['data-node-_name'] = $node->getName();
		}

		if ($node->getParent() && $node->getParent()->isAutoCreated()) {
			// we shall only add these properties if the parent is actually auto-created; as the Node-Type-Switcher in the UI relies on that.
			$attributes['data-node-__parent-node-name'] = $node->getParent()->getName();
			$attributes['data-node-__grandparent-node-type'] = $node->getParent()->getParent()->getNodeType()->getName();
		}

		$attributes = $this->addNodePropertyAttributes($node, $attributes);

		return $this->htmlAugmenter->addAttributes($content, $attributes);
	}

	/**
	 * Adds node properties to the given $attributes collection and returns the extended array
	 *
	 * @param NodeInterface $node
	 * @param array $attributes
	 * @return array the merged attributes
	 */
	public function addNodePropertyAttributes(NodeInterface $node, array $attributes) {
		foreach ($node->getNodeType()->getProperties() as $propertyName => $propertyConfiguration) {
			if (substr($propertyName, 0, 2) === '__') {
				// skip fully-private properties
				continue;
			}
			/** @var $contentContext ContentContext */
			$contentContext = $node->getContext();
			if ($propertyName === '_name' && $node === $contentContext->getCurrentSiteNode()) {
				// skip the node name of the site node
				continue;
			}
			// Serialize objects to JSON strings
			$dataType = isset($propertyConfiguration['type']) ? $propertyConfiguration['type'] : 'string';
			$dasherizedPropertyName = $this->dasherize($propertyName);
			$attributes['data-node-' . $dasherizedPropertyName] = $this->getNodeProperty($node, $propertyName, $dataType);
			if ($dataType !== 'string') {
				$prefixedDataType = $dataType === 'jsonEncoded' ? 'typo3:jsonEncoded' : 'xsd:' . $dataType;
				$attributes['data-nodedatatype-' . $dasherizedPropertyName] = $prefixedDataType;
			}
		}
		return $attributes;
	}

	/**
	 * TODO This implementation is directly linked to the inspector editors, since they need the actual values,
	 * this should change to use TypeConverters
	 *
	 * @param NodeInterface $node
	 * @param string $propertyName
	 * @param string $dataType
	 * @return string
	 */
	protected function getNodeProperty(NodeInterface $node, $propertyName, &$dataType) {
		if (substr($propertyName, 0, 1) === '_') {
			$propertyValue = ObjectAccess::getProperty($node, substr($propertyName, 1));
		} else {
			$propertyValue = $node->getProperty($propertyName);
		}

		// Enforce an integer value for integer properties as otherwise javascript will give NaN and VIE converts it to an array containing 16 times 'NaN'
		if ($dataType === 'integer') {
			$propertyValue = (integer)$propertyValue;
		}

		// Serialize boolean values to String
		if ($dataType === 'boolean') {
			return $propertyValue ? 'true' : 'false';
		}

		// Serialize array values to String
		if ($dataType === 'array') {
			return $propertyValue ? json_encode($propertyValue, JSON_UNESCAPED_UNICODE) : NULL;
		}

		// Serialize date values to String
		if ($dataType === 'DateTime') {
			if (!$propertyValue instanceof \DateTime) {
				return '';
			}
			$value = clone $propertyValue;
			return $value->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::W3C);
		}

		// Serialize node references to node identifiers
		if ($dataType === 'references') {
			$nodeIdentifiers = array();
			if (is_array($propertyValue)) {
				/** @var $subNode NodeInterface */
				foreach ($propertyValue as $subNode) {
					$nodeIdentifiers[] = $subNode->getIdentifier();
				}
			}
			return json_encode($nodeIdentifiers);
		}

		// Serialize node reference to node identifier
		if ($dataType === 'reference') {
			if ($propertyValue instanceof NodeInterface) {
				return $propertyValue->getIdentifier();
			} else {
				return '';
			}
		}

		if ($propertyValue instanceof \TYPO3\Media\Domain\Model\ImageInterface) {
			$propertyMappingConfiguration = new \TYPO3\Flow\Property\PropertyMappingConfiguration();
			$propertyMappingConfiguration->setTypeConverterOption('TYPO3\Media\TypeConverter\ImageInterfaceJsonSerializer', ImageInterfaceJsonSerializer::CONFIGURATION_ESCAPE_SLASHES, TRUE);
			return $this->imageInterfaceJsonSerializer->convertFrom($propertyValue, 'string', array(), $propertyMappingConfiguration);
		}

		// Serialize an Asset to JSON (the NodeConverter expects JSON for object type properties)
		if ($dataType === ltrim('TYPO3\Media\Domain\Model\Asset', '\\') && $propertyValue !== NULL) {
			if ($propertyValue instanceof \TYPO3\Media\Domain\Model\Asset) {
				return json_encode($this->persistenceManager->getIdentifierByObject($propertyValue));
			}
		}

		// Serialize an array of Assets to JSON
		if (is_array($propertyValue)) {
			$parsedType = \TYPO3\Flow\Utility\TypeHandling::parseType($dataType);
			if ($parsedType['elementType'] === ltrim('TYPO3\Media\Domain\Model\Asset', '\\')) {
				$convertedValues = array();
				foreach ($propertyValue as $singlePropertyValue) {
					if ($singlePropertyValue instanceof \TYPO3\Media\Domain\Model\Asset) {
						$convertedValues[] = $this->persistenceManager->getIdentifierByObject($singlePropertyValue);
					}
				}
				return json_encode($convertedValues);
			}
		}
		return $propertyValue === NULL ? '' : $propertyValue;
	}

	/**
	 * @param NodeInterface $node
	 * @return boolean
	 */
	protected function hasInlineEditableProperties(NodeInterface $node) {
		foreach (array_values($node->getNodeType()->getProperties()) as $propertyConfiguration) {
			if (isset($propertyConfiguration['ui']['inlineEditable']) && $propertyConfiguration['ui']['inlineEditable'] === TRUE) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Converts camelCased strings to lower cased and non-camel-cased strings
	 *
	 * @param string $value
	 * @return string
	 */
	protected function dasherize($value) {
		return strtolower(trim(preg_replace('/[A-Z]/', '-$0', $value), '-'));
	}

}
