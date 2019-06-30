<?php

function dw_semaphore_test(): void
{
  global $wpdb;

  // wait to acquire a semaphore
  $semaphore = DW_Semaphore::wait('dw_semaphore_test');

  // generate ID for testing
  $id = random_int(1, 100);

  // log start
  $wpdb->insert('dw_log', ['entry' => 'start ' . $id], '%s');

  // do big critical calculation
  sleep(5);

  // log end
  $wpdb->insert('dw_log', ['entry' => 'end  ' . $id], '%s');

  // signal the semaphore to release
  $semaphore->signal();
}

add_action('init', 'dw_semaphore_test');