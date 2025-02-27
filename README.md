## Moodle BrainMaster
This is a branch of the project 
<p align="center"><a href="https://moodle.org" target="_blank" title="Moodle Website">
  <img src="https://raw.githubusercontent.com/moodle/moodle/main/.github/moodlelogo.svg" alt="The Moodle Logo">
</a></p>

[Moodle][1] is the World's Open Source Learning Platform, widely used around the world by countless universities, schools, companies, and all manner of organisations and individuals.

Moodle is designed to allow educators, administrators and learners to create personalised learning environments with a single robust, secure and integrated system.

## Innovations to main Moodle


### Ability to register answer time

Setting 
$CFG->storetime = true;
in  config.php activates the storing of the response time to every question. More, also the time spent on the feedback is recorded.
All data is easily accessible by the view question_attempt_view stored in the script folder 


### Repetition of wrong anwers

Setting
$CFG->repeat_errors = 3;

in  config.php  will require the student to give three correct answers for each question they fail on the first attempt. Every time the student gives a wrong answer, the consecutive correct answers counter is reset, and they must provide three more correct responses to that same question. The question will only disappear after the student answers correctly three times in a row. The number three can be replaced with a different value. If set to 1, only a single correct response will be required.



### Integration with BrainMaster

Setting
$CFG->BrainMasterService = 'http://192.168.1.175:5000/api/';

in config.php with a BrainMaster service address enables integration with the BrainMaster neural network.
It is also required to set $CFG->storetime = true; when interacting with BrainMaster.

When enabled, the BrainMaster service interacts with Moodle to optimize test proposals and improve the efficiency of the study process. In this mode, all tests are available under the dummy test "BrainMaster," while all other tests are automatically hidden from the course.

BrainMaster operates in two stages:

#### Training Stage

In the first stage, it adopts a default teaching strategy until it reaches a sufficient level of confidence in its predictions about the student's knowledge and study skills.
During this stage, tests are repeatedly proposed until the error rate falls below a configured threshold, at which point new tests are introduced.

#### Full Integration Stage

At this stage, the system selects quizzes based on one of the following modes:

- Tests available from the last lesson, but never attempted
- Tests available but never attempted
- Questions last answered incorrectly in the last lesson
- Questions last answered incorrectly in the last two lessons
- Questions last answered incorrectly
- Questions answered incorrectly in one of the last three attempts
- Focused test on a difficult lesson
- Random test on all previously asked questions

The selection is based on predictions about the student's knowledge state.



# Explanation of the changes
Overall, the modifications aim to:

-  Store in a new action field of quiz_attempt the action taken by BrainMaster when calculating the quiz to be administered to the student. This field remains null for quizzes not managed by BrainMaster.
- Store in a row of the mdl_question_attempt_step_data table the timestamps of when the student moves to the next quiz. This is identified by the name 'next_page_timestamp'.
- Store in a row of the mdl_question_attempt_step_data table the timestamps of when the student answers a single question. This is identified by the name '-submit_stamp'.
- Prevent the student from returning to previous questions during a quiz and from reviewing answers on the summary page. This is necessary in order to measure the time he spend on each phase.
- Request BrainMaster, only for quizzes named "BrainMaster" (there should be only one per course), to dynamically compose quizzes based on the lessons attended by the student and predictions of which quiz would be most effective for learning and review.
- Notify BrainMaster when a student completes a lesson, to enable quizzes for that section, provided all lessons in that section have been completed.
- Notify BrainMaster when a student completes a quiz, so it can extract relevant data for continuous neural network training and adapt quizzes to the student's knowledge level.
- Allow the same question to appear multiple times within the same quiz (see the next point).
- Reintroduce incorrect answers at the end of the quiz, requiring the student to answer them correctly a predefined number of consecutive times (default: 3) before they are no longer shown. 


## File mod/quiz/classes/quiz_attempt.php

### Method load_questions()
Since the quizzes are dynamically created, the number of slots in the quiz selected by the user does not match the number of slots in the generated quiz. In fact, the BrainMaster quiz typically contains only a single "informational" question, so it will never match the requested number of slots.
Therefore, it is necessary to insert as many slots as needed for the questions in the loop.

A loop has been added to this method, which applies only to "BrainMaster" quizzes.

> ```php
>  if ($this->get_quiz_name()=="BrainMaster"){
>            // New slots are added dynamically when needed. 
>            // The Brain Master quiz initially contains only one question (informative),
>            // while the actual questions are injected at runtime by the Brain Master service.
>            // However, the quiz is associated with a single slot, which is insufficient.
>            //
>            // To accommodate the dynamically generated quiz structure, we simulate 
>            // the presence of additional slots and pages.                        
>            while (count($this->slots) < $this->quba->question_count()) {   
>                $first = reset($this->slots);
>                if ($first && is_object($first)) {
>                    $new = clone $first;
>                    $last = end($this->slots);
>                    $new->slot = $last->slot + 1;
>                    $new->displaynumber = $last->displaynumber + 1;
>                    $this->slots[] = $new;
>                }      
>            }
>        }



### Method  process_attempt($timenow, $finishattempt, $timeup, $thispage)


> ```php
>
>   if ($CFG->repeat_errors>0){                
>                //Appends failed questions to the end of current attempt when necessary           
>                $uniqueid = $this->get_uniqueid();
>                $params = array('uniqueid' => $uniqueid, 'consecutive'=>$CFG->repeat_errors);
>                $DB->execute("CALL process_question(:uniqueid, :consecutive)", $params);    
>            }
>
>

This section has been added to invoke a stored procedure when the student answers a question, in order to enqueue the questions they answer incorrectly until they answer them correctly a configured number of times.



## File question\engine\datalib.php

### Method prepare_step_data(question_attempt_step $step, $stepid, $context, $insert=false)

> ```php
> if ($CFG->storetime && ($name == '-submit') && $insert){
>   // Stores additional data in the log, in order to measure the elapsed time
>   //  for each response, and also the time spento on viewing the feedback  
>   $data = new stdClass();
> 	$data->attemptstepid = $stepid;
>	  $data->name =  $name . "_stamp";
>	  $data->value = time();
>		$rows[] = $data;
> }
> //...
>
> if (isset($_SESSION['last_nextpage_timestamp'])) {
>		  $next_page_data = new stdClass();
>		  $next_page_data->attemptstepid = $stepid;
> 		$next_page_data->name = "next_page_timestamp";
>	  	$next_page_data->value = $_SESSION['last_nextpage_timestamp'];
>		  $rows[] = $next_page_data;
>		  unset($_SESSION['last_nextpage_timestamp']);
> }
>

This method contains the code that, when the row with the field -submit is saved, also saves the timestamp, but only during the initial insertion of the row. Additionally, if the session variable last_nextpage_timestamp is set, its value is saved in the row where the name field is set to next_page_timestamp.

These two values are used to measure response time, which is generally calculated as the difference between the next_page_timestamp of the previous question and the -submit_timestamp of the current question.

Conversely, the difference between the next_page_timestamp and the -submit_timestamp of the previous question represents the time spent on the question's feedback.

The -submit_stamp is only generated when $insert is true, i.e. when the row is first inserted in the table. In order to achieve this behaviour, the parameter $insert has been added to the interface, and it is passed as false by the method update_question_attempt_step, while method insert_question_attempt_step passes it as true, as it inserts a new row..


### Method  insert_question_attempt_metadata(question_attempt $qa, array $names) 

This section has been added:

> ```php
>            if ($CFG->storetime && $name == "-submit"){
>                // store the stamp in order to measure the time spent on viewing the
>                //  feedback on the single response
>                $data = new stdClass();
>                $data->attemptstepid = $firststep->get_id();
>                $data->name = ':_' . $name . "_stamp";
>                $data->value = $firststep->get_metadata_var($name . "_stamp");
>                $rows[] = $data;
>            }
>

just to be sure that the row is always added jointly with -submit. I am not sure that it is necessary.



## File mod\quiz\processattempt.php

> ```php
>   // When the user clicks "Next Page", save the timestamp in a session variable.
>    if ($CFG->storetime && $next && !$finishattempt && !$timeup && isset($attemptid)) {
>        // store session timestamp for "Next Page".
>        if (!isset($_SESSION['last_nextpage_timestamp'])){
>            $_SESSION['last_nextpage_timestamp']= time();
>        }   
>    }
>

This code was added to record the timestamp of the next page click, which is later retrieved by the prepare_step_data method in the question/engine/datalib.php file.



## File mod\quiz\classes\quiz_settings.php

### Method preload_questions(string $userid=null, int $action=null) 

> ```php
>    if ($this->quiz->name === "BrainMaster" && (!empty($CFG->BrainMasterService)) &&  $action !== null){
>              // If the quiz name is "BrainMaster", the answers are dynamically retrieved from an external 
>              //   service, specifically the Brain Master neural network.
>              $slots = qbank_helper::get_brainmaster_structure($userid, $this->course->id, $action);
>          }
>    else {            
>        // For other quizzes, the standard question structure is used.
>        $slots = qbank_helper::get_question_structure($this->quiz->id, $this->context);
>    }
>

This section has been modified to retrieve the list of questions from the BrainMaster service instead of the database when the quiz is named "BrainMaster."

The most significant change, and perhaps the most impactful one as it affects the data structure, is that the type of the questions property in the quiz_settings class has been changed from a dictionary to a list. This was necessary to allow the repetition of questions within the same quiz until the student answers correctly a configured number of times.


> ```php
>    $this->questions = [];
>            foreach ($slots as $slot) {
>                // Previously, $this->questions was a dictionary. Now it's a list to allow repetitions
>                // of the same question in the quiz.
>                $this->questions[] = $slot; 
>            }
>



## File mod\quiz\classes\output\renderer.php




### Method  summary_page_controls($attemptobj)

> ```php
>        // Return to place button.
>        if (!$CFG->storetime){
>            if ($attemptobj->get_state() == quiz_attempt::IN_PROGRESS) {
>                $button = new single_button(
>                        new moodle_url($attemptobj->attempt_url(null, $attemptobj->get_currentpage())),
>                        get_string('returnattempt', 'quiz'));
>                $output .= $this->container($this->container($this->render($button),
>                        'controls'), 'submitbtns mdl-align');
>            }
>        }


In order to accurately measure response times and time spent on feedback, the presence of back buttons has been controlled in this and other sections. In this case, the button to return to the quiz has been removed.


Similarly, the quiz flow has been conditioned based on the free or sequential navigation mode, provided that response times are being measured.


> ```php
>      $isSequentialMode = ($quiz->navmethod === 'sequential') && $CFG->storetime;
>      //submission_confirmation needs two params 
>      $this->page->requires->js_call_amd('mod_quiz/submission_confirmation', 'init', [$totalunanswered,$isSequentialMode]);
>

Consistently, in the same file, this setting has been used to control the "Back" buttons in other sections:

### Method attempt_form($attemptobj, $page, $slots, $id, $nextpage)

> ```php
>      $navmethod = $attemptobj->get_quiz()->navmethod; 
>      //Brain Master: adding parameter $attemptobj
>      $output .= $this->attempt_navigation_buttons($page, $attemptobj->is_last_page($page),  $attemptobj, $navmethod);
>



### Method  attempt_page($attemptobj, $page, $accessmanager, $messages, $slots, $id, $nextpage) 

> ```php
>      $isSequentialMode = ($quiz->navmethod === 'sequential') && $CFG->storetime;;
>      if (!$isSequentialMode){
>            //Don't show back button if mode is sequential
>            $output .= $this->during_attempt_tertiary_nav($attemptobj->view_url());
>        }
>


### Method summary_page($attemptobj, $displayoptions)

> ```php
>        $isSequentialMode = ($quiz->navmethod === 'sequential')  && $CFG->storetime;
>        if (!$isSequentialMode){
>            //Only shows back button when navigation is 'free'. 
>            $output .= $this->during_attempt_tertiary_nav($attemptobj->view_url());
>        }

### Method start_attempt_page(quiz_settings $quizobj, preflight_check_form $mform)

> ```php
>        $isSequentialMode = ($quizobj->get_quiz()->navmethod === 'sequential');
>        if (!$isSequentialMode){
>            $output .= $this->during_attempt_tertiary_nav($quizobj->view_url());
>        }	


### Method attempt_navigation_buttons($page, $lastpage, $attemptobj, $navmethod = 'free')

> ```php
>      // Show "Next Page" button only if the question has been answered or has no choices.
>      if ($question_attempt->get_state()->is_finished() || !$has_choices) {
>            $output .= html_writer::empty_tag('input', ['type' => 'submit', 'name' => 'next',
>            'value' => $nextlabel, 'class' => 'mod_quiz-next-nav btn btn-primary', 'id' => 'mod_quiz-next-nav']);
>      } 
>
In this case, the "Next" button has been hidden if the question has not yet been answered. Unlike the default behavior, it will not be possible to go back and answer later. Additionally, leaving a question blank is not allowed. The student must either answer correctly or make a mistake and receive feedback on the error.

> ```php
>     if ($question_attempt->get_state()->is_finished() || !$has_choices) {
>            $this->page->requires->js_call_amd('core_form/submit', 'init', ['mod_quiz-next-nav']);
>     }
>

Similarly, in this case, submitting has been disabled unless an answer has been provided, except for informational questions.


## File mod\quiz\amd\src\submission_confirmation.js 

### listener registerEventListeners = (unAnsweredQuestions, isSequentialMode) 

In this case, the confirmation message before submitting has been removed, as we are preventing the possibility of going back when the navigation mode is sequential, making it pointless.

> ```php
>          if (isSequentialMode) {
>                // Skips confirmation and send it directly
>                submitAction.closest(SELECTOR.attemptSubmitForm).submit();
>                return;
>          }



## File lib\completionlib.php

### function notify_external_service($userid, $sectionid, $courseid) 

This function has been added with the purpose of notifying BrainMaster when a student completes a lesson. If all the lessons of a section are completed, BrainMaster will activate the quizzes for that section.

### update_state($cm, $possibleresult=COMPLETION_UNKNOWN, $userid=0, $override = false, $isbulkupdate = false) 

In this function, the notify_external_service function is called.

> ```php
>     self::notify_external_service($current->userid, $current->coursemoduleid, $this->course_id);
>


## File mod\quiz\summary.php

### send_answers($attempt_id)

E' stata aggiunta questa funzione, che ha lo scopo di inviare a BrainMaster l'id del quiz_attempt che lo studente ha appena sostenuto. BrainMaster provvederà a prelevare i dati in autonomia.

Questa funzione è invocata qualora sia valorizzato il campo action di quiz_attempt

> ```php
>    if ($attemptobj->get_attempt()->action !== null){
>        // If the quiz is associated with Brain Master, send the result to the Brain Master Service
>        send_answers($attemptid);
>    }
>



## File mod/quiz/classes/question/bank/qbank_helper.php

### static function get_brainmaster_structure(string $userid, int $idcourse, ?int $action )

This method has been added, which, with an output equivalent to the pre-existing get_question_structure, queries the BrainMaster service to retrieve the list of questions to include in the quiz.

To avoid code duplication, a new method has been introduced:

### prepare_slot(stdClass  $slot)

This method performs exactly the same tasks that the get_question_structure method did with the slots after loading the data from the database.


## File lib\locallib.php

### method get_action($userid, $courseid)

This method has been added, which is used to populate the (new) action field of the question_attempt table. This field is subsequently used to dynamically compose the quiz, from the BrainMaster service.

## method quiz_create_attempt(quiz_settings $quizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null)

> ```php
>        $attempt->action = null;
>        if ($quizobj->get_quiz_name()=="BrainMaster"){
>            //obtain the action from the external service
>            $attempt->action = get_action($userid, $quizobj->get_course()->id);
>        } 
>

The aforementioned method is invoked when the quiz name is BrainMaster.


### Method quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow,  $questionids = [], $forcedvariantsbyslot = [])

> ```php
>    // Partially load all the questions in this quiz.
>    $quizobj->preload_questions($attempt->userid, $attempt->action); //load questions in $quizobj->questions

The only modification here was adding the parameters to invoke preload_questions, which previously did not have them.


## File mod/quiz/db/install.xml

Alla tabella 
> <TABLE NAME="quiz_attempts" COMMENT="Stores users attempts at quizzes.">

è stato aggiunto il campo 

> <FIELD NAME="action" TYPE="int" LENGTH="3" NOTNULL="false" DEFAULT="0" SEQUENCE="false" COMMENT="Action taken by the BrainMaster"/> 


## License

Moodle BrainMaster is provided freely as open source software, under version 3 of the GNU General Public License.
