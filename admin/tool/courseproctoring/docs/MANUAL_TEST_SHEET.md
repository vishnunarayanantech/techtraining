# Manual test sheet (for a person, with the real widget)

Proctoring must be tested by hand. Nothing in the automated tests opens the real vendor widget, camera or service.
Use a **new test course** that has nothing to do with real learners, and test accounts.

## Before you start

* You need: a site administrator login, a teacher account, a student account, and from the vendor an **App Key** and a
  **Key ID** for the test course.
* Read `VENDOR_QUESTIONS.md`. Several things (how long a session may stay open, what happens in two tabs, what a closed
  session looks like) are not confirmed by the vendor. Your test is how we find out. Write down what you see.
* If the same course is proctored by another set-up on your site, switch that off for the test course first. Two widgets
  on one page would both ask for the camera.

## The tests

| # | Do this | You should see |
|---|---|---|
| 1 | **Administrator:** open Site administration > Plugins > Admin tools > Course proctoring. Type the App Key, tick **Enable vendor integration**, save. | A green message that the integration is **ON**. The App Key is not shown again, only the last four characters. |
| 2 | **Administrator:** edit the **new test course** (Course > Settings). Open the section **Course Proctoring Key Setting**, type the Key ID in **Proctor Key**, save. | The course saves. Opening the settings again shows the key. |
| 2b | **Teacher:** open the same course settings page. | There is **no** "Course Proctoring Key Setting" section. |
| 3 | **Student** (enrolled in the test course; use a browser that may use the camera): open the course page. | The proctoring widget starts (it asks for the camera, as the vendor designed). |
| 3b | Same student: open two or three other pages of the course (a Page, a Book, a File), then close the browser, come back **on another day** or in **another browser**, and open the course again. | The widget starts each time. At the **vendor**, this learner has **one** session for this course, whose id starts with `course_`. Note whether the vendor shows one session or several. |
| 4 | Same student: open a **quiz** in the course. | The course widget is **not** on any page of the quiz. (If that quiz has its own proctoring key from another integration, only that widget may appear. Never two.) |
| 5 | **Teacher**, **administrator**, and an administrator who uses **Log in as** the student: open the course. | **No** widget for any of them. |
| 6 | Open a course that has **no** Proctor Key. | No widget. |
| 7 | **Student:** complete the course (finish its activities so that Moodle marks the course complete). | At the vendor the session is shown **closed**. Opening the course again shows **no** widget. |
| 7b | **Student, with the widget showing:** press **Mark as done** on the activity that completes the course (a course whose only condition is that activity makes this easy). **Do not reload the page.** | The widget **disappears at once** (it is unmounted and the camera and screen-share lights go off). **No pop-up** such as "Error: Session not initialized", and no redirect. Write down exactly what you see, and the time. |
| 7c | Another student, widget showing: press **Mark as done** on an activity that does **not** complete the course. | The widget **stays**. (Marking something done must never switch proctoring off early.) |
| 8 | Use a second student: start a session (as in test 3), then an administrator **un-enrols** or **suspends** that student. | At the vendor the session is shown **closed**. |
| 9 | **Administrator:** untick **Enable vendor integration**, save. Then, as a student with an open session, open the course. | No widget. (When the switch is on again, the same student continues with the same session.) |
| 10 | **Administrator:** empty the **Proctor Key** of the test course, save. Then open the course as a student. | No widget on new page views. |

## When something looks wrong

* **The widget does not start.** The reasons are listed in `DESIGN.md` ("How a page is decided"). The usual ones: the switch
  is off; no App Key; the course has no Proctor Key; the person is not actively enrolled or is not a Student (permission
  `tool/courseproctoring:beproctored`); the person completed the course already; the page is a quiz page.
* **A pop-up "Error: Session not initialized. Please start session." still appears after "Mark as done" (test 7b).** That message
  comes from the vendor's service, not from Moodle. It means the widget sent something in the moment between the server
  closing the session and the page stopping the widget. Note the time and send it to us with the vendor question 10.
* **Something is wrong at the vendor** (a session that stays open, or two sessions for one learner). Note the learner, the
  course and the time, and read the ledger below.

## Reading the ledger (needs database access; read only)

One row per learner and course. `status`: 0 active, 1 closed, 2 failed. Replace `mdl_` with your table prefix.

    SELECT s.id, u.username, c.shortname, s.keyid, s.sessionid, s.status, s.closeattempts, s.lasterror,
           FROM_UNIXTIME(s.timecreated) AS started, FROM_UNIXTIME(s.timeclosed) AS closed
      FROM mdl_tool_courseproctoring_session s
      JOIN mdl_user u ON u.id = s.userid
      JOIN mdl_course c ON c.id = s.courseid
     ORDER BY s.id DESC;

* `status` 0 with `closeattempts` above 0: the vendor could not be reached when the session should have closed. A retry
  needs Moodle cron.
* `status` 2: closing was given up. `lasterror` says why in a few words (never a key).
* The audit events (Site administration > Reports > Logs, component "Course proctoring") list each change of a Proctor Key
  and of the settings, without the keys.

## To stop everything quickly

Untick **Enable vendor integration** on the plugin's admin page. No widget starts anywhere. Sessions that are already open
at the vendor stay open until their learner completes or leaves the course.
