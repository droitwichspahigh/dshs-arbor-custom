#!/usr/bin/env php
<?php

/* Unfortunately, once Composed, you'll need to edit src/Arbor/Model/UserDefinedRecords and remove the \ModelBase type from setEntity */

require "env.php";

require "vendor/autoload.php";

$gqlclient = new \RabelosCoder\GraphQL\Client($arbor_url . '/graphql/query', [],
	['Authorization' => 'Basic ' . base64_encode($arbor_user . ':' . $arbor_password)]);

$restapi = new \Arbor\Api\Gateway\RestGateway(
	$arbor_url,
	$arbor_user,
	$arbor_password
);
\Arbor\Model\ModelBase::setDefaultGateway($restapi);

/* Get the bits we need */

$page = 0;

$merged = [ 'Consent' => [], 'UserDefinedRecord' => []];

do {
	$query = <<<EOF
	query {
	  Consent (page_num: $page) {
	    displayName
	    status
	    student {
	      id
	    }
	  }
	  UserDefinedRecord (page_num: $page userDefinedField__code: "$arbor_matching_userdefinedfield_code") {
	    userDefinedRecord
	    entity {
	      __typename
	    }
	  }
	}
EOF;
	/* UserDefinedRecord (page_num: $page userDefinedField__code: "SCHOOL__STUDENT__04C_THUMBS_CONSENT") { */
	/* Don't bother catching exception, let it crash */
	$response = $gqlclient->query($query, [])->send();
	#print_r(get_object_vars($response)['data']);
	for ($i=0; $i<count($response->data->Consent); $i++) {
		if (!isset($response->data->Consent[$i]))
			break;
		$merged['Consent'][] = $response->data->Consent[$i];
	}
	for ($i=0; $i<count($response->data->UserDefinedRecord); $i++) {
		if (!isset($response->data->UserDefinedRecord[$i]))
			break;
		$merged['UserDefinedRecord'][] = $response->data->UserDefinedRecord[$i];
	}
	$page++;
} while (!(empty($response->data->Consent) && empty($response->data->UserDefinedRecord)));

#print_r($merged);

$students = [];

foreach ($merged['Consent'] as $c) {
	if ($c->displayName == "Biometric Data") {
		if ($c->status == 'CONSENTED') {
			$students[$c->student->id] = 'Yes';
		} else {
			$students[$c->student->id] = 'No';
		}

	}
}

$todelete = [];
$tochange = [];

foreach ($merged['UserDefinedRecord'] as $u) {
	/* Is this student accounted for? */
	if (!isset($students[$u->entity->id])) {
		$todelete[] = $u->id;
	} elseif ($u->userDefinedRecord != $students[$u->entity->id]) {
		$tochange[$u->id] = $students[$u->entity->id];
	}
	unset($students[$u->entity->id]);
}

/* Delete irrelevant ones $todelete */

foreach ($todelete as $d) {
	echo "Delete $d\n";
	$del = \Arbor\Model\UserDefinedRecord::retrieve($d);
	$restapi->delete($del);
}

/* Change incorrect ones $tochange */

foreach ($tochange as $k => $v) {
	echo "Change $k -> $v\n";
	$change = \Arbor\Model\UserDefinedRecord::retrieve($k);
	$change->setValue($v);
	$change->save();
}

/* Add new records for missing ones $students */

$udf = \Arbor\Model\UserDefinedField::retrieve($arbor_matching_userdefinedfield_code);
foreach ($students as $k => $v) {
	echo "Add new record for $k as $v\n";
	$student = \Arbor\Model\Student::retrieve($k);
	$new = new \Arbor\Model\UserDefinedRecord();
	$new->setValue($v);
	$new->setEntity($student);
	$new->setUserDefinedField($udf);
	$new->save();
}
