<?php

namespace Elastica\Test\Type;

use Elastica\Client;
use Elastica\Document;
use Elastica\Query;
use Elastica\Query\QueryString;
use Elastica\Test\Base as BaseTest;
use Elastica\Type;
use Elastica\Type\Mapping;

class MappingTest extends BaseTest
{
    public function testMappingStoreFields()
    {
        $client = $this->_getClient();
        $index = $client->getIndex('test');

        $index->create(array(), true);
        $type = $index->getType('test');

        $mapping = new Mapping($type,
            array(
                'firstname' => array('type' => 'string', 'store' => 'yes'),
                // default is store => no expected
                'lastname' => array('type' => 'string'),
            )
        );
        $mapping->disableSource();

        $type->setMapping($mapping);

        $firstname = 'Nicolas';
        $doc = new Document(1,
            array(
                'firstname' => $firstname,
                'lastname' => 'Ruflin'
            )
        );

        $type->addDocument($doc);

        $index->refresh();
        $queryString = new QueryString('ruflin');
        $query = Query::create($queryString);
        $query->setFields(array('*'));

        $resultSet = $type->search($query);
        $result = $resultSet->current();
        $fields = $result->getFields();

        $this->assertEquals($firstname, $fields['firstname']);
        $this->assertArrayNotHasKey('lastname', $fields);
        $this->assertEquals(1, count($fields));

        $index->flush();
        $document = $type->getDocument(1);

        $this->assertEmpty($document->getData());
    }

    public function testEnableAllField()
    {
        $index = $this->_createIndex();
        $type = $index->getType('test');

        $mapping = new Mapping($type, array());

        $mapping->enableAllField();

        $data = $mapping->toArray();
        $this->assertTrue($data[$type->getName()]['_all']['enabled']);

        $response = $mapping->send();
        $this->assertTrue($response->isOk());
    }

    public function testEnableTtl()
    {
        $client = $this->_getClient();
        $index = $client->getIndex('test');

        $index->create(array(), true);
        $type = $index->getType('test');

        $mapping = new Mapping($type, array());

        $mapping->enableTtl();

        $data = $mapping->toArray();
        $this->assertTrue($data[$type->getName()]['_ttl']['enabled']);
    }

    public function testNestedMapping()
    {
        $client = $this->_getClient();
        $index = $client->getIndex('test');

        $index->create(array(), true);
        $type = $index->getType('test');

        $this->markTestIncomplete('nested mapping is not set right yet');
        $mapping = new Mapping($type,
            array(
                'test' => array(
                    'type' => 'object', 'store' => 'yes', 'properties' => array(
                        'user' => array(
                            'properties' => array(
                                'firstname' => array('type' => 'string', 'store' => 'yes'),
                                'lastname' => array('type' => 'string', 'store' => 'yes'),
                                'age' => array('type' => 'integer', 'store' => 'yes'),
                            )
                        ),
                    ),
                ),
            )
        );

        $type->setMapping($mapping);

        $doc = new Document(1, array(
            'user' => array(
                'firstname' => 'Nicolas',
                'lastname' => 'Ruflin',
                'age' => 9
            ),
        ));

        //print_r($type->getMapping());
        //exit();
        $type->addDocument($doc);

        $index->refresh();
        $resultSet = $type->search('ruflin');
        //print_r($resultSet);
    }

    public function testParentMapping()
    {
        $index = $this->_createIndex();
        $parenttype = new Type($index, 'parenttype');
        $parentmapping = new Mapping($parenttype,
            array(
                'name' => array('type' => 'string', 'store' => 'yes')
            )
        );

        $parenttype->setMapping($parentmapping);

        $childtype = new Type($index, 'childtype');
        $childmapping = new Mapping($childtype,
            array(
                'name' => array('type' => 'string', 'store' => 'yes'),
            )
        );
        $childmapping->setParent('parenttype');

        $childtype->setMapping($childmapping);

        $data = $childmapping->toArray();
        $this->assertEquals('parenttype', $data[$childtype->getName()]['_parent']['type']);
    }

    public function testMappingExample()
    {
        $index = $this->_createIndex();
        $type = $index->getType('notes');

        $mapping = new Mapping($type,
            array(
                'note' => array(
                    'store' => 'yes', 'properties' => array(
                        'titulo'  => array('type' => 'string', 'store' => 'no', 'include_in_all' => true, 'boost' => 1.0),
                        'contenido' => array('type' => 'string', 'store' => 'no', 'include_in_all' => true, 'boost' => 1.0)
                    )
                )
            )
        );

        $type->setMapping($mapping);

        $doc = new Document(1, array(
                'note' => array(
                    array(
                        'titulo'        => 'nota1',
                        'contenido'        => 'contenido1'
                    ),
                    array(
                        'titulo'        => 'nota2',
                        'contenido'        => 'contenido2'
                    )
                )
            )
        );

        $type->addDocument($doc);
    }

    /**
     * Test setting a dynamic template and validate whether the right mapping is applied after adding a document which
     * should match the dynamic template. The example is the template_1 from the Elasticsearch documentation.
     * 
     * @link http://www.elasticsearch.org/guide/reference/mapping/root-object-type/
     */
    public function testDynamicTemplate()
    {
        $index = $this->_createIndex();
        $type  = $index->getType('person');
        
        // set a dynamic template "template_1" which creates a multi field for multi* matches. 
        $mapping = new Mapping($type);
        $mapping->setParam('dynamic_templates', array(
            array('template_1' => array(
                'match'   => 'multi*',
                'mapping' => array(
                    'type'   => 'multi_field',
                    'fields' => array(
                        '{name}' => array('type' => '{dynamic_type}', 'index' => 'analyzed'),
                        'org'    => array('type' => '{dynamic_type}', 'index' => 'not_analyzed')
                    )
                )
            ))
        ));
        
        $mapping->send();
        
        // create a document which should create a mapping for the field: multiname.
        $testDoc = new Document('person1', array('multiname' => 'Jasper van Wanrooy'), $type);
        $index->addDocuments(array($testDoc));
        
        // read the mapping from Elasticsearch and assert that the multiname.org field is "not_analyzed"
        $newMapping = $type->getMapping();
        $this->assertArrayHasKey('person', $newMapping,
            'Person type not available in mapping from ES. Mapping set at all?');
        $this->assertArrayHasKey('properties', $newMapping['person'],
            'Person type doesnt have any properties. Document properly added?');
        $this->assertArrayHasKey('multiname', $newMapping['person']['properties'],
            'The multiname property is not added to the mapping. Document properly added?');
        $this->assertArrayHasKey('fields', $newMapping['person']['properties']['multiname'],
            'The multiname field of the Person type is presumably not a multi_field type. Dynamic mapping not applied?');
        $this->assertArrayHasKey('org', $newMapping['person']['properties']['multiname']['fields'],
            'The multi* matcher did not create a mapping for the multiname.org property when indexing the document.');
        $this->assertArrayHasKey('index', $newMapping['person']['properties']['multiname']['fields']['org'],
            'Indexing status of the multiname.org not available. Dynamic mapping not fully applied!');
        $this->assertEquals('not_analyzed', $newMapping['person']['properties']['multiname']['fields']['org']['index']);
    }

    public function testSetMeta()
    {
        $index = $this->_createIndex();
        $type = $index->getType('test');
        $mapping = new Mapping($type, array(
            'firstname' => array('type' => 'string', 'store' => 'yes'),
            'lastname' => array('type' => 'string')
        ));
        $mapping->setMeta(array('class' => 'test'));
        $type->setMapping($mapping);

        $mappingData = $type->getMapping();
        $this->assertEquals('test', $mappingData['test']['_meta']['class']);
    }
}
