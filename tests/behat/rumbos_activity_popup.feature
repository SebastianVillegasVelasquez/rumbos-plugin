@format @format_rumbos @javascript
Feature: Activity popups over the Rumbos map
  In order to use an activity without leaving the map
  As a student
  I need the activity to open in a popup, to come back to the map from it, and to have the progress refreshed

  Background:
    Given the following config values are set as admin:
      | config    | value                  | plugin        |
      | enabled   | 1                      | format_rumbos |
      | appurl    | https://rumbos.invalid | format_rumbos |
      | timeoutms | 120000                 | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Course 1 | C1        | rumbos | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name   | content          | course | idnumber | section | completion | completionview |
      | page     | Page 1 | A page body text | C1     | page1    | 1       | 2          | 1              |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready

  Scenario: An activity opens in a popup with a way back to the map
    When the Rumbos app asks to open the activity "Page 1"
    Then ".format-rumbos-popup[role='dialog'][aria-modal='true']" "css_element" should exist
    And "iframe[name='rumbos-popup']" "css_element" should exist
    And I should see "Page 1" in the ".format-rumbos-popup" "css_element"
    And I should see "Back to the map" in the ".format-rumbos-popup" "css_element"
    And the Rumbos app should have been sent "rumbos:host-overlay:true" last

  Scenario: The popup frames the activity page itself
    When the Rumbos app asks to open the activity "Page 1"
    And I switch to "rumbos-popup" iframe
    Then I should see "A page body text"
    And I switch to the main frame

  Scenario: Viewing the activity refreshes the state while the popup is still open
    When the Rumbos app asks to open the activity "Page 1"
    Then the Rumbos app should have been sent "rumbos:host-overlay:true, rumbos:host-state" last
    And I should see "Page 1" in the ".format-rumbos-popup" "css_element"

  Scenario: A module in the polled list keeps the progress current while its popup stays on one page
    Given the following config values are set as admin:
      | config      | value | plugin        |
      | pollmodules | page  | format_rumbos |
    And the following "activities" exist:
      | activity | name   | content   | course | idnumber | section | completion |
      | page     | Page 2 | Page two. | C1     | page2    | 1       | 1          |
    And I reload the page
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    And the Rumbos app asks to open the activity "Page 2"
    When the activity "Page 2" is completed for "student1" in the background
    And I wait "20" seconds
    Then the Rumbos app should have been sent "rumbos:host-overlay:true, rumbos:host-state" last

  Scenario: A module outside the polled list is not checked until the popup closes
    Given the following config values are set as admin:
      | config      | value | plugin        |
      | pollmodules |       | format_rumbos |
    And the following "activities" exist:
      | activity | name   | content   | course | idnumber | section | completion |
      | page     | Page 2 | Page two. | C1     | page2    | 1       | 1          |
    And I reload the page
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    And the Rumbos app asks to open the activity "Page 2"
    When the activity "Page 2" is completed for "student1" in the background
    And I wait "20" seconds
    Then the Rumbos app should have been sent "rumbos:host-overlay:true" last
    And I click on "Back to the map" "button" in the ".format-rumbos-popup" "css_element"
    And the Rumbos app should have been sent "rumbos:host-state, rumbos:host-overlay:false" last

  Scenario: Closing the popup refreshes the state, then tells the app the overlay is gone
    Given the Rumbos app asks to open the activity "Page 1"
    When I click on "Back to the map" "button" in the ".format-rumbos-popup" "css_element"
    Then ".format-rumbos-popup" "css_element" should not exist
    And the Rumbos app should have been sent "rumbos:host-state, rumbos:host-overlay:false" last

  Scenario: Pressing Escape closes the popup
    Given the Rumbos app asks to open the activity "Page 1"
    When I press the escape key
    Then ".format-rumbos-popup" "css_element" should not exist
    And the Rumbos app should have been sent "rumbos:host-state, rumbos:host-overlay:false" last

  Scenario: An activity that continues to the course page closes the popup instead of showing the map in it
    Given the Rumbos app asks to open the activity "Page 1"
    When the activity in the popup goes to the course page
    Then the Rumbos popup should close
    And "iframe[data-region='rumbos-frame']" "css_element" should exist
    And the Rumbos app should have been sent "rumbos:host-state, rumbos:host-overlay:false" last

  Scenario: A module in the new-tab list opens in a new tab
    Given the following config values are set as admin:
      | config        | value | plugin        |
      | newtabmodules | page  | format_rumbos |
    And I reload the page
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app asks to open the activity "Page 1"
    Then ".format-rumbos-popup" "css_element" should not exist
    And a new tab should have been requested for the activity "Page 1"
