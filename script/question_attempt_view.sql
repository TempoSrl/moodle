ALTER TABLE `moodle`.`mdl_quiz_attempts` 
ADD COLUMN `action` BIGINT NULL AFTER `gradednotificationsenttime`;

CREATE VIEW question_attempt_view AS
WITH times AS (
    SELECT 
  		QA.id,
        QQ.course,
        QAT.action,
        QAT.userid,
		QU.id as usage_id,
        QA.questionid,
        QA.questionsummary AS question,
        QA.responsesummary AS resp,
        QA.rightanswer AS _right,
        SUBSTR(QAS.state, 7) AS result,
        
        -- Calcolo `ask_time`
        COALESCE(
            (SELECT FROM_UNIXTIME(QA2.value) 
             FROM mdl_question_attempt_step_data QA2 
             WHERE QA2.attemptstepid = QASD.attemptstepid AND QA2.name = 'next_page_timestamp'),
            (SELECT FROM_UNIXTIME(QAS2.timecreated) 
             FROM mdl_question_attempt_steps QAS2 
             WHERE QAS2.questionattemptid = QAS.questionattemptid AND QAS2.state = 'todo')
        ) AS ask_time,
        
        -- Calcolo `resp_time`
        FROM_UNIXTIME(QASD.value) AS resp_time,
        
        -- Calcolo 'next page' su quella domanda, che si trova nella domanda successiva
         (SELECT FROM_UNIXTIME(QA2.value) 
             FROM mdl_question_attempt_step_data QA2 
             WHERE QA2.attemptstepid = QASD.attemptstepid+1 AND QA2.name = 'next_page_timestamp') as next_page_time
    FROM 
        mdl_question_usages QU        
        JOIN mdl_quiz_attempts QAT ON QAT.uniqueid = qu.id
        join mdl_quiz qq on QAT.quiz = qq.id
        JOIN mdl_question_attempts QA ON QA.questionusageid = QU.id
        JOIN mdl_question_attempt_steps QAS ON QAS.questionattemptid = QA.id
        JOIN mdl_question_attempt_step_data QASD ON QASD.attemptstepid = QAS.id
    WHERE 
        -- QU.id = 96  AND 
        QASD.name = '-submit_stamp'
)

SELECT 
	times.id,times.action,
    usage_id, questionid,  question,  resp,  _right, result, ask_time, resp_time, next_page_time,
    TIMESTAMPDIFF(SECOND, ask_time, resp_time) AS elapsed_time,
    TIMESTAMPDIFF(SECOND, resp_time, next_page_time) AS review_time,
    times.course,
    times.userid
FROM 
    times;
	