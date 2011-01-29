<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Tests\Component\Form;

require_once __DIR__ . '/Fixtures/Author.php';
require_once __DIR__ . '/Fixtures/TestField.php';
require_once __DIR__ . '/Fixtures/TestForm.php';

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormContext;
use Symfony\Component\Form\Field;
use Symfony\Component\Form\FieldError;
use Symfony\Component\Form\HiddenField;
use Symfony\Component\Form\PropertyPath;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Tests\Component\Form\Fixtures\Author;
use Symfony\Tests\Component\Form\Fixtures\TestField;
use Symfony\Tests\Component\Form\Fixtures\TestForm;

class FormTest_PreconfiguredForm extends Form
{
    protected function configure()
    {
        $this->add(new Field('firstName'));

        parent::configure();
    }
}

class TestSetDataBeforeConfigureForm extends Form
{
    protected $testCase;
    protected $object;

    public function __construct($testCase, $name, $object, $validator)
    {
        $this->testCase = $testCase;
        $this->object = $object;

        parent::__construct($name, $object, $validator);
    }

    protected function configure()
    {
        $this->testCase->assertEquals($this->object, $this->getData());

        parent::configure();
    }
}

class FormTest extends \PHPUnit_Framework_TestCase
{
    protected $validator;
    protected $form;

    public static function setUpBeforeClass()
    {
        @session_start();
    }

    protected function setUp()
    {
        $this->validator = $this->createMockValidator();
        $this->form = new Form('author', array('validator' => $this->validator));
    }

    public function testNoCsrfProtectionByDefault()
    {
        $form = new Form('author');

        $this->assertFalse($form->isCsrfProtected());
    }

    public function testCsrfProtectionCanBeEnabled()
    {
        $form = new Form('author', array(
            'csrf_provider' => $this->createMockCsrfProvider(),
        ));

        $this->assertTrue($form->isCsrfProtected());
    }

    public function testCsrfFieldNameCanBeSet()
    {
        $form = new Form('author', array(
            'csrf_provider' => $this->createMockCsrfProvider(),
            'csrf_field_name' => 'foobar',
        ));

        $this->assertEquals('foobar', $form->getCsrfFieldName());
    }

    public function testCsrfProtectedFormsHaveExtraField()
    {
        $provider = $this->createMockCsrfProvider();
        $provider->expects($this->once())
                ->method('generateCsrfToken')
                ->with($this->equalTo('Symfony\Component\Form\Form'))
                ->will($this->returnValue('ABCDEF'));

        $form = new Form('author', array(
            'csrf_provider' => $provider,
        ));

        $this->assertTrue($form->has($this->form->getCsrfFieldName()));

        $field = $form->get($form->getCsrfFieldName());

        $this->assertTrue($field instanceof HiddenField);
        $this->assertEquals('ABCDEF', $field->getDisplayedData());
    }

    public function testIsCsrfTokenValidPassesIfCsrfProtectionIsDisabled()
    {
        $this->form->bind(array());

        $this->assertTrue($this->form->isCsrfTokenValid());
    }

    public function testIsCsrfTokenValidPasses()
    {
        $provider = $this->createMockCsrfProvider();
        $provider->expects($this->once())
                ->method('isCsrfTokenValid')
                ->with($this->equalTo('Symfony\Component\Form\Form'), $this->equalTo('ABCDEF'))
                ->will($this->returnValue(true));

        $form = new Form('author', array(
            'csrf_provider' => $provider,
            'validator' => $this->validator,
        ));

        $field = $form->getCsrfFieldName();

        $form->bind(array($field => 'ABCDEF'));

        $this->assertTrue($form->isCsrfTokenValid());
    }

    public function testIsCsrfTokenValidFails()
    {
        $provider = $this->createMockCsrfProvider();
        $provider->expects($this->once())
                ->method('isCsrfTokenValid')
                ->with($this->equalTo('Symfony\Component\Form\Form'), $this->equalTo('ABCDEF'))
                ->will($this->returnValue(false));

        $form = new Form('author', array(
            'csrf_provider' => $provider,
            'validator' => $this->validator,
        ));

        $field = $form->getCsrfFieldName();

        $form->bind(array($field => 'ABCDEF'));

        $this->assertFalse($form->isCsrfTokenValid());
    }

    public function testValidationGroupNullByDefault()
    {
        $this->assertNull($this->form->getValidationGroups());
    }

    public function testValidationGroupsCanBeSetToString()
    {
        $form = new Form('author', array(
            'validation_groups' => 'group',
        ));

        $this->assertEquals(array('group'), $form->getValidationGroups());
    }

    public function testValidationGroupsCanBeSetToArray()
    {
        $form = new Form('author', array(
            'validation_groups' => array('group1', 'group2'),
        ));

        $this->assertEquals(array('group1', 'group2'), $form->getValidationGroups());
    }

    public function testBindUsesValidationGroups()
    {
        $field = $this->createMockField('firstName');
        $form = new Form('author', array(
            'validation_groups' => 'group',
            'validator' => $this->validator,
        ));
        $form->add($field);

        $this->validator->expects($this->once())
                                        ->method('validate')
                                        ->with($this->equalTo($form), $this->equalTo(array('group')));

        $form->bind(array()); // irrelevant
    }

//    public function testBindThrowsExceptionIfNoValidatorIsSet()
//    {
//        $field = $this->createMockField('firstName');
//        $form = new Form('author');
//        $form->add($field);
//
//        $this->setExpectedException('Symfony\Component\Form\Exception\FormException');
//
//        $form->bind(array()); // irrelevant
//    }

    public function testBindRequest()
    {
        $values = array(
            'author' => array(
                'name' => 'Bernhard',
                'image' => array('filename' => 'foobar.png'),
            ),
        );
        $files = array(
            'author' => array(
                'error' => array('image' => array('file' => UPLOAD_ERR_OK)),
                'name' => array('image' => array('file' => 'upload.png')),
                'size' => array('image' => array('file' => 123)),
                'tmp_name' => array('image' => array('file' => 'abcdef.png')),
                'type' => array('image' => array('file' => 'image/png')),
            ),
        );

        $request = new Request(array(), $values, array(), array(), $files);

        $form = new Form('author', array('validator' => $this->validator));
        $form->add(new TestField('name'));
        $imageForm = new Form('image');
        $imageForm->add(new TestField('file'));
        $imageForm->add(new TestField('filename'));
        $form->add($imageForm);

        $form->bindRequest($request);

        $file = new UploadedFile('abcdef.png', 'upload.png', 'image/png', 123, UPLOAD_ERR_OK);

        $this->assertEquals('Bernhard', $form['name']->getData());
        $this->assertEquals('foobar.png', $form['image']['filename']->getData());
        $this->assertEquals($file, $form['image']['file']->getData());
    }

    public function testBindGlobals()
    {
        $_POST = array(
            'author' => array(
                'name' => 'Bernhard',
                'image' => array('filename' => 'foobar.png'),
            ),
        );
        $_FILES = array(
            'author' => array(
                'error' => array('image' => array('file' => UPLOAD_ERR_OK)),
                'name' => array('image' => array('file' => 'upload.png')),
                'size' => array('image' => array('file' => 123)),
                'tmp_name' => array('image' => array('file' => 'abcdef.png')),
                'type' => array('image' => array('file' => 'image/png')),
            ),
        );


        $form = new Form('author', array('validator' => $this->validator));
        $form->add(new TestField('name'));
        $imageForm = new Form('image');
        $imageForm->add(new TestField('file'));
        $imageForm->add(new TestField('filename'));
        $form->add($imageForm);

        $form->bindGlobals();

        $file = new UploadedFile('abcdef.png', 'upload.png', 'image/png', 123, UPLOAD_ERR_OK);

        $this->assertEquals('Bernhard', $form['name']->getData());
        $this->assertEquals('foobar.png', $form['image']['filename']->getData());
        $this->assertEquals($file, $form['image']['file']->getData());
    }

    public function testUpdateFromPropertyIsIgnoredIfPropertyPathIsNull()
    {
        $author = new Author();
        $author->child = new Author();
        $standaloneChild = new Author();

        $form = new Form('child');
        $form->setData($standaloneChild);
        $form->setPropertyPath(null);
        $form->updateFromProperty($author);

        // should not be $author->child!!
        $this->assertSame($standaloneChild, $form->getData());
    }

    public function testUpdatePropertyIsIgnoredIfPropertyPathIsNull()
    {
        $author = new Author();
        $author->child = $child = new Author();
        $standaloneChild = new Author();

        $form = new Form('child');
        $form->setData($standaloneChild);
        $form->setPropertyPath(null);
        $form->updateProperty($author);

        // $author->child was not modified
        $this->assertSame($child, $author->child);
    }

    public function testSupportsArrayAccess()
    {
        $form = new Form('author');
        $form->add($this->createMockField('firstName'));
        $this->assertEquals($form->get('firstName'), $form['firstName']);
        $this->assertTrue(isset($form['firstName']));
    }

    public function testSupportsUnset()
    {
        $form = new Form('author');
        $form->add($this->createMockField('firstName'));
        unset($form['firstName']);
        $this->assertFalse(isset($form['firstName']));
    }

    public function testDoesNotSupportAddingFields()
    {
        $form = new Form('author');
        $this->setExpectedException('LogicException');
        $form[] = $this->createMockField('lastName');
    }

    public function testSupportsCountable()
    {
        $form = new Form('group');
        $form->add($this->createMockField('firstName'));
        $form->add($this->createMockField('lastName'));
        $this->assertEquals(2, count($form));

        $form->add($this->createMockField('australian'));
        $this->assertEquals(3, count($form));
    }

    public function testSupportsIterable()
    {
        $form = new Form('group');
        $form->add($field1 = $this->createMockField('field1'));
        $form->add($field2 = $this->createMockField('field2'));
        $form->add($field3 = $this->createMockField('field3'));

        $expected = array(
            'field1' => $field1,
            'field2' => $field2,
            'field3' => $field3,
        );

        $this->assertEquals($expected, iterator_to_array($form));
    }

    public function testIsBound()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $this->assertFalse($form->isBound());
        $form->bind(array('firstName' => 'Bernhard'));
        $this->assertTrue($form->isBound());
    }

    public function testValidIfAllFieldsAreValid()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $form->add($this->createValidMockField('firstName'));
        $form->add($this->createValidMockField('lastName'));

        $form->bind(array('firstName' => 'Bernhard', 'lastName' => 'Potencier'));

        $this->assertTrue($form->isValid());
    }

    public function testInvalidIfFieldIsInvalid()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $form->add($this->createInvalidMockField('firstName'));
        $form->add($this->createValidMockField('lastName'));

        $form->bind(array('firstName' => 'Bernhard', 'lastName' => 'Potencier'));

        $this->assertFalse($form->isValid());
    }

    public function testInvalidIfBoundWithExtraFields()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $form->add($this->createValidMockField('firstName'));
        $form->add($this->createValidMockField('lastName'));

        $form->bind(array('foo' => 'bar', 'firstName' => 'Bernhard', 'lastName' => 'Potencier'));

        $this->assertTrue($form->isBoundWithExtraFields());
    }

    public function testHasNoErrorsIfOnlyFieldHasErrors()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $form->add($this->createInvalidMockField('firstName'));

        $form->bind(array('firstName' => 'Bernhard'));

        $this->assertFalse($form->hasErrors());
    }

    public function testBindForwardsPreprocessedData()
    {
        $field = $this->createMockField('firstName');

        $form = $this->getMock(
            'Symfony\Component\Form\Form',
            array('preprocessData'), // only mock preprocessData()
            array('author', array('validator' => $this->validator))
        );

        // The data array is prepared directly after binding
        $form->expects($this->once())
              ->method('preprocessData')
              ->with($this->equalTo(array('firstName' => 'Bernhard')))
              ->will($this->returnValue(array('firstName' => 'preprocessed[Bernhard]')));
        $form->add($field);

        // The preprocessed data is then forwarded to the fields
        $field->expects($this->once())
                    ->method('bind')
                    ->with($this->equalTo('preprocessed[Bernhard]'));

        $form->bind(array('firstName' => 'Bernhard'));
    }

    public function testBindForwardsNullIfValueIsMissing()
    {
        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('bind')
                    ->with($this->equalTo(null));

        $form = new Form('author', array('validator' => $this->validator));
        $form->add($field);

        $form->bind(array());
    }

    public function testAddErrorMapsFieldValidationErrorsOntoFields()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('addError')
                    ->with($this->equalTo($error));

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('fields[firstName].data');

        $form->addError($error, $path->getIterator(), Form::FIELD_ERROR);
    }

    public function testAddErrorMapsFieldValidationErrorsOntoFieldsWithinNestedForms()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('addError')
                    ->with($this->equalTo($error));

        $form = new Form('author');
        $innerGroup = new Form('names');
        $innerGroup->add($field);
        $form->add($innerGroup);

        $path = new PropertyPath('fields[names].fields[firstName].data');

        $form->addError($error, $path->getIterator(), Form::FIELD_ERROR);
    }

    public function testAddErrorKeepsFieldValidationErrorsIfFieldNotFound()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('foo');
        $field->expects($this->never())
                    ->method('addError');

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('fields[bar].data');

        $form->addError($error, $path->getIterator(), Form::FIELD_ERROR);

        $this->assertEquals(array($error), $form->getErrors());
    }

    public function testAddErrorKeepsFieldValidationErrorsIfFieldIsHidden()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('firstName');
        $field->expects($this->any())
                    ->method('isHidden')
                    ->will($this->returnValue(true));
        $field->expects($this->never())
                    ->method('addError');

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('fields[firstName].data');

        $form->addError($error, $path->getIterator(), Form::FIELD_ERROR);

        $this->assertEquals(array($error), $form->getErrors());
    }

    public function testAddErrorMapsDataValidationErrorsOntoFields()
    {
        $error = new FieldError('Message');

        // path is expected to point at "firstName"
        $expectedPath = new PropertyPath('firstName');
        $expectedPathIterator = $expectedPath->getIterator();

        $field = $this->createMockField('firstName');
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('firstName')));
        $field->expects($this->once())
                    ->method('addError')
                    ->with($this->equalTo($error), $this->equalTo($expectedPathIterator), $this->equalTo(Form::DATA_ERROR));

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('firstName');

        $form->addError($error, $path->getIterator(), Form::DATA_ERROR);
    }

    public function testAddErrorKeepsDataValidationErrorsIfFieldNotFound()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('foo');
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('foo')));
        $field->expects($this->never())
                    ->method('addError');

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('bar');

        $form->addError($error, $path->getIterator(), Form::DATA_ERROR);
    }

    public function testAddErrorKeepsDataValidationErrorsIfFieldIsHidden()
    {
        $error = new FieldError('Message');

        $field = $this->createMockField('firstName');
        $field->expects($this->any())
                    ->method('isHidden')
                    ->will($this->returnValue(true));
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('firstName')));
        $field->expects($this->never())
                    ->method('addError');

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('firstName');

        $form->addError($error, $path->getIterator(), Form::DATA_ERROR);
    }

    public function testAddErrorMapsDataValidationErrorsOntoNestedFields()
    {
        $error = new FieldError('Message');

        // path is expected to point at "street"
        $expectedPath = new PropertyPath('address.street');
        $expectedPathIterator = $expectedPath->getIterator();
        $expectedPathIterator->next();

        $field = $this->createMockField('address');
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('address')));
        $field->expects($this->once())
                    ->method('addError')
                    ->with($this->equalTo($error), $this->equalTo($expectedPathIterator), $this->equalTo(Form::DATA_ERROR));

        $form = new Form('author');
        $form->add($field);

        $path = new PropertyPath('address.street');

        $form->addError($error, $path->getIterator(), Form::DATA_ERROR);
    }

    public function testAddErrorMapsErrorsOntoFieldsInVirtualGroups()
    {
        $error = new FieldError('Message');

        // path is expected to point at "address"
        $expectedPath = new PropertyPath('address');
        $expectedPathIterator = $expectedPath->getIterator();

        $field = $this->createMockField('address');
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('address')));
        $field->expects($this->once())
                    ->method('addError')
                    ->with($this->equalTo($error), $this->equalTo($expectedPathIterator), $this->equalTo(Form::DATA_ERROR));

        $form = new Form('author');
        $nestedForm = new Form('nested', array('virtual' => true));
        $nestedForm->add($field);
        $form->add($nestedForm);

        $path = new PropertyPath('address');

        $form->addError($error, $path->getIterator(), Form::DATA_ERROR);
    }

    public function testAddThrowsExceptionIfAlreadyBound()
    {
        $form = new Form('author', array('validator' => $this->validator));
        $form->add($this->createMockField('firstName'));
        $form->bind(array('firstName' => 'Bernhard'));

        $this->setExpectedException('Symfony\Component\Form\Exception\AlreadyBoundException');
        $form->add($this->createMockField('lastName'));
    }

    public function testAddSetsFieldParent()
    {
        $form = new Form('author');

        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('setParent');
                    // PHPUnit fails to compare infinitely recursive objects
                    //->with($this->equalTo($form));

        $form->add($field);
    }

    public function testRemoveUnsetsFieldParent()
    {
        $form = new Form('author');

        $field = $this->createMockField('firstName');
        $field->expects($this->exactly(2))
                    ->method('setParent');
                    // PHPUnit fails to compare subsequent method calls with different arguments

        $form->add($field);
        $form->remove('firstName');
    }

    public function testAddUpdatesFieldFromTransformedData()
    {
        $originalAuthor = new Author();
        $transformedAuthor = new Author();
        // the authors should differ to make sure the test works
        $transformedAuthor->firstName = 'Foo';

        $form = new TestForm('author');

        $transformer = $this->createMockTransformer();
        $transformer->expects($this->once())
                                ->method('transform')
                                ->with($this->equalTo($originalAuthor))
                                ->will($this->returnValue($transformedAuthor));

        $form->setValueTransformer($transformer);
        $form->setData($originalAuthor);

        $field = $this->createMockField('firstName');
        $field->expects($this->any())
                    ->method('getPropertyPath')
                    ->will($this->returnValue(new PropertyPath('firstName')));
        $field->expects($this->once())
                    ->method('updateFromProperty')
                    ->with($this->equalTo($transformedAuthor));

        $form->add($field);
    }

    public function testAddDoesNotUpdateFieldIfTransformedDataIsEmpty()
    {
        $originalAuthor = new Author();

        $form = new TestForm('author');

        $transformer = $this->createMockTransformer();
        $transformer->expects($this->once())
                                ->method('transform')
                                ->with($this->equalTo($originalAuthor))
                                ->will($this->returnValue(''));

        $form->setValueTransformer($transformer);
        $form->setData($originalAuthor);

        $field = $this->createMockField('firstName');
        $field->expects($this->never())
                    ->method('updateFromProperty');

        $form->add($field);
    }

    /**
     * @expectedException Symfony\Component\Form\Exception\UnexpectedTypeException
     */
    public function testAddThrowsExceptionIfNoFieldOrString()
    {
        $form = new Form('author');

        $form->add(1234);
    }

    /**
     * @expectedException Symfony\Component\Form\Exception\FormException
     */
    public function testAddThrowsExceptionIfAnonymousField()
    {
        $form = new Form('author');

        $field = $this->createMockField('');

        $form->add($field);
    }

    public function testAddThrowsExceptionIfStringButNoFieldFactory()
    {
        $rootForm = $this->createMockForm();
        $rootForm->expects($this->once())
                ->method('getFieldFactory')
                ->will($this->returnValue(null));

        $form = new Form('author');
        $form->setParent($rootForm);

        $this->setExpectedException('\LogicException');

        $form->add('firstName');
    }

    public function testAddUsesFieldFromFactoryIfStringIsGiven()
    {
        $author = new \stdClass();
        $field = $this->createMockField('firstName');

        $factory = $this->getMock('Symfony\Component\Form\FieldFactory\FieldFactoryInterface');
        $factory->expects($this->once())
                ->method('getInstance')
                ->with($this->equalTo($author), $this->equalTo('firstName'), $this->equalTo(array('foo' => 'bar')))
                ->will($this->returnValue($field));

        $rootForm = $this->createMockForm();
        $rootForm->expects($this->once())
                ->method('getFieldFactory')
                ->will($this->returnValue($factory));

        $form = new Form('author');
        $form->setParent($rootForm);
        $form->setData($author);
        $form->add('firstName', array('foo' => 'bar'));

        $this->assertSame($field, $form['firstName']);
    }

    public function testSetDataUpdatesAllFieldsFromTransformedData()
    {
        $originalAuthor = new Author();
        $transformedAuthor = new Author();
        // the authors should differ to make sure the test works
        $transformedAuthor->firstName = 'Foo';

        $form = new TestForm('author');

        $transformer = $this->createMockTransformer();
        $transformer->expects($this->once())
                                ->method('transform')
                                ->with($this->equalTo($originalAuthor))
                                ->will($this->returnValue($transformedAuthor));

        $form->setValueTransformer($transformer);

        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('updateFromProperty')
                    ->with($this->equalTo($transformedAuthor));

        $form->add($field);

        $field = $this->createMockField('lastName');
        $field->expects($this->once())
                    ->method('updateFromProperty')
                    ->with($this->equalTo($transformedAuthor));

        $form->add($field);

        $form->setData($originalAuthor);
    }

    /**
     * The use case for this test are groups whose fields should be mapped
     * directly onto properties of the form's object.
     *
     * Example:
     *
     * <code>
     * $dateRangeField = new Form('dateRange');
     * $dateRangeField->add(new DateField('startDate'));
     * $dateRangeField->add(new DateField('endDate'));
     * $form->add($dateRangeField);
     * </code>
     *
     * If $dateRangeField is not virtual, the property "dateRange" must be
     * present on the form's object. In this property, an object or array
     * with the properties "startDate" and "endDate" is expected.
     *
     * If $dateRangeField is virtual though, it's children are mapped directly
     * onto the properties "startDate" and "endDate" of the form's object.
     */
    public function testSetDataSkipsVirtualForms()
    {
        $author = new Author();
        $author->firstName = 'Foo';

        $form = new Form('author');
        $nestedForm = new Form('personal_data', array(
            'virtual' => true,
        ));

        // both fields are in the nested group but receive the object of the
        // top-level group because the nested group is virtual
        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('updateFromProperty')
                    ->with($this->equalTo($author));

        $nestedForm->add($field);

        $field = $this->createMockField('lastName');
        $field->expects($this->once())
                    ->method('updateFromProperty')
                    ->with($this->equalTo($author));

        $nestedForm->add($field);

        $form->add($nestedForm);
        $form->setData($author);
    }

    public function testSetDataThrowsAnExceptionIfArgumentIsNotObjectOrArray()
    {
        $form = new Form('author');

        $this->setExpectedException('InvalidArgumentException');

        $form->setData('foobar');
    }

    public function testBindUpdatesTransformedDataFromAllFields()
    {
        $originalAuthor = new Author();
        $transformedAuthor = new Author();
        // the authors should differ to make sure the test works
        $transformedAuthor->firstName = 'Foo';

        $form = new TestForm('author', array('validator' => $this->validator));

        $transformer = $this->createMockTransformer();
        $transformer->expects($this->exactly(2))
                                ->method('transform')
                                // the method is first called with NULL, then
                                // with $originalAuthor -> not testable by PHPUnit
                                // ->with($this->equalTo(null))
                                // ->with($this->equalTo($originalAuthor))
                                ->will($this->returnValue($transformedAuthor));

        $form->setValueTransformer($transformer);
        $form->setData($originalAuthor);

        $field = $this->createMockField('firstName');
        $field->expects($this->once())
                    ->method('updateProperty')
                    ->with($this->equalTo($transformedAuthor));

        $form->add($field);

        $field = $this->createMockField('lastName');
        $field->expects($this->once())
                    ->method('updateProperty')
                    ->with($this->equalTo($transformedAuthor));

        $form->add($field);

        $form->bind(array()); // irrelevant
    }

    public function testGetDataReturnsObject()
    {
        $form = new Form('author');
        $object = new \stdClass();
        $form->setData($object);
        $this->assertEquals($object, $form->getData());
    }

    public function testGetDisplayedDataForwardsCall()
    {
        $field = $this->createValidMockField('firstName');
        $field->expects($this->atLeastOnce())
                    ->method('getDisplayedData')
                    ->will($this->returnValue('Bernhard'));

        $form = new Form('author');
        $form->add($field);

        $this->assertEquals(array('firstName' => 'Bernhard'), $form->getDisplayedData());
    }

    public function testIsMultipartIfAnyFieldIsMultipart()
    {
        $form = new Form('author');
        $form->add($this->createMultipartMockField('firstName'));
        $form->add($this->createNonMultipartMockField('lastName'));

        $this->assertTrue($form->isMultipart());
    }

    public function testIsNotMultipartIfNoFieldIsMultipart()
    {
        $form = new Form('author');
        $form->add($this->createNonMultipartMockField('firstName'));
        $form->add($this->createNonMultipartMockField('lastName'));

        $this->assertFalse($form->isMultipart());
    }

    public function testSupportsClone()
    {
        $form = new Form('author');
        $form->add($this->createMockField('firstName'));

        $clone = clone $form;

        $this->assertNotSame($clone['firstName'], $form['firstName']);
    }

    public function testBindWithoutPriorSetData()
    {
        return; // TODO
        $field = $this->createMockField('firstName');
        $field->expects($this->any())
                    ->method('getData')
                    ->will($this->returnValue('Bernhard'));

        $form = new Form('author');
        $form->add($field);

        $form->bind(array('firstName' => 'Bernhard'));

        $this->assertEquals(array('firstName' => 'Bernhard'), $form->getData());
    }

    public function testGetHiddenFieldsReturnsOnlyHiddenFields()
    {
        $form = $this->getGroupWithBothVisibleAndHiddenField();

        $hiddenFields = $form->getHiddenFields(true, false);

        $this->assertSame(array($form['hiddenField']), $hiddenFields);
    }

    public function testGetVisibleFieldsReturnsOnlyVisibleFields()
    {
        $form = $this->getGroupWithBothVisibleAndHiddenField();

        $visibleFields = $form->getVisibleFields(true, false);

        $this->assertSame(array($form['visibleField']), $visibleFields);
    }

    /**
     * Create a group containing two fields, "visibleField" and "hiddenField"
     *
     * @return Form
     */
    protected function getGroupWithBothVisibleAndHiddenField()
    {
        $form = new Form('testGroup');

        // add a visible field
        $visibleField = $this->createMockField('visibleField');
        $visibleField->expects($this->once())
                    ->method('isHidden')
                    ->will($this->returnValue(false));
        $form->add($visibleField);

        // add a hidden field
        $hiddenField = $this->createMockField('hiddenField');
        $hiddenField->expects($this->once())
                    ->method('isHidden')
                    ->will($this->returnValue(true));
        $form->add($hiddenField);

        return $form;
    }

    protected function createMockField($key)
    {
        $field = $this->getMock(
            'Symfony\Component\Form\FieldInterface',
            array(),
            array(),
            '',
            false, // don't use constructor
            false  // don't call parent::__clone
        );

        $field->expects($this->any())
                    ->method('getKey')
                    ->will($this->returnValue($key));

        return $field;
    }

    protected function createMockForm()
    {
        $form = $this->getMock(
            'Symfony\Component\Form\Form',
            array(),
            array(),
            '',
            false, // don't use constructor
            false  // don't call parent::__clone)
        );

        $form->expects($this->any())
                ->method('getRoot')
                ->will($this->returnValue($form));

        return $form;
    }

    protected function createInvalidMockField($key)
    {
        $field = $this->createMockField($key);
        $field->expects($this->any())
                    ->method('isValid')
                    ->will($this->returnValue(false));

        return $field;
    }

    protected function createValidMockField($key)
    {
        $field = $this->createMockField($key);
        $field->expects($this->any())
                    ->method('isValid')
                    ->will($this->returnValue(true));

        return $field;
    }

    protected function createNonMultipartMockField($key)
    {
        $field = $this->createMockField($key);
        $field->expects($this->any())
                    ->method('isMultipart')
                    ->will($this->returnValue(false));

        return $field;
    }

    protected function createMultipartMockField($key)
    {
        $field = $this->createMockField($key);
        $field->expects($this->any())
                    ->method('isMultipart')
                    ->will($this->returnValue(true));

        return $field;
    }

    protected function createMockTransformer()
    {
        return $this->getMock('Symfony\Component\Form\ValueTransformer\ValueTransformerInterface', array(), array(), '', false, false);
    }

    protected function createMockValidator()
    {
        return $this->getMock('Symfony\Component\Validator\ValidatorInterface');
    }

    protected function createMockCsrfProvider()
    {
        return $this->getMock('Symfony\Component\Form\CsrfProvider\CsrfProviderInterface');
    }
}
