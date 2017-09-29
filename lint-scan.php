<?php

/*
 * Parse array of results, extract the problems
 * and return as a well-structed array.
 */

function vipgoci_lint_get_issues(
	$pr_number,
	$file_name,
	$file_issues_arr
) {

	$file_issues_arr_new = array();

	// Loop through everything we got from the command
	foreach( $file_issues_arr as $index => $line ) {
		if (
			( 0 === strpos( $line, 'No syntax errors detected' ) )
		) {
			// Skip non-errors we do not care about
			continue;
		}


		/*
		 * Catch any syntax-error problems
		 */

		$pos = strpos( $line, ' on line ' );

		if ( false !== $pos ) {
			$pos2 = strpos( $line, ' ', $pos );

			$file_line = substr(
				$line,
				$pos + strlen(' on line '),
				$pos2
			);

			$file_issues_arr_new[ $file_line ][] = array(
				'message' => $line,
				'level' => 'ERROR'
			);
		}
	}

	return $file_issues_arr_new;
}

/*
 * Execute linter, get results and
 * return them to caller.
 */

function vipgoci_lint_do_scan(
	$temp_file_name
) {
	/*
	 * Prepare command to use, make sure
	 * to grab all the output, also
	 * the output to STDERR.
	 */
	$cmd = sprintf(
		'( php -l %s 2>&1 )',
		escapeshellarg( $temp_file_name )
	);


	$file_issues_arr = array();

	// Execute linter
	exec( $cmd, $file_issues_arr );

	return $file_issues_arr;
}


/**
 * Run PHP lint on all files in a path
 */
function vipgoci_lint_scan_commit(
	$options,
	&$commit_issues_submit,
	&$commit_issues_stats
) {
	$repo_owner = $options['repo-owner'];
	$repo_name  = $options['repo-name'];
	$commit_id  = $options['commit'];
	$github_token = $options['token'];


	vipgoci_log(
		'About to lint PHP-files',

		array(
			'repo_owner'	=> $repo_owner,
			'repo_name'	=> $repo_name,
			'commit_id'	=> $commit_id,
		)
	);

	$commit_info = vipgoci_phpcs_github_fetch_commit_info(
		$repo_owner,
		$repo_name,
		$commit_id,
		$github_token,
		array(
			'file_extensions'
				=> array( 'php' ),

			'status'
				=> array( 'added', 'modified' ),
		)
	);

	$prs_implicated = vipgoci_phpcs_github_prs_implicated(
		$repo_owner,
		$repo_name,
		$commit_id,
		$github_token
	);



	foreach( $prs_implicated as $pr_item ) {
		/*
		 * Initialize array for stats and
		 * results of scanning, if needed.
		 */

		if ( empty( $commit_issues_submit[ $pr_item->number ] ) ) {
			$commit_issues_submit[ $pr_item->number ] = array(
			);
		}

		if ( empty( $commit_issues_stats[ $pr_item->number ] ) ) {
			$commit_issues_stats[ $pr_item->number ] = array(
				'error'         => 0,
				'warning'       => 0
			);
		}
	}


	foreach( $commit_info->files as $file_info ) {
		$file_contents = vipgoci_phpcs_github_fetch_committed_file(
			$repo_owner,
			$repo_name,
			$github_token,
			$commit_id,
			$file_info->filename,
			$options['local-git-repo']
                );

		$temp_file_name = vipgoci_save_temp_file(
			'phpcs-scan-',
			$file_contents
		);

		/*
		 * Actually lint the file
		 */

		vipgoci_log(
			'About to PHP-lint file',

			array(
				'repo_owner' => $repo_owner,
				'repo_name' => $repo_name,
				'commit_id' => $commit_id,
				'filename' => $file_info->filename,
				'temp_file_name' => $temp_file_name,
			)
		);

		$file_issues_arr = vipgoci_lint_do_scan(
			$temp_file_name
		);


		/* Get rid of temporary file */
		unset( $temp_file_name );

		/*
		 * Process the results, get them in an array format
		 */

		$file_issues_arr = vipgoci_lint_get_issues(
			$pr_item->number,
			$file_info->filename,
			$file_issues_arr
		);


		// If there are no new issues, just leave it at that
		if ( empty( $file_issues_arr ) ) {
			return;
		}

		$file_issues_arr_master = $file_issues_arr;

		/*
		 * Process results of linting
		 * for each Pull-Request -- actually
		 * queue issues for submission.
		 */
		foreach( $prs_implicated as $pr_item ) {
			$file_changed_lines = vipgoci_patch_changed_lines(
				$repo_owner,
				$repo_name,
				$github_token,
				$pr_item->base->sha,
				$commit_id,
				$file_info->filename
			);

			/*
			 * Filter out any issues that affect the file, but are not
			 * due to the commit made -- so any existing issues are left
			 * out and not commented on by us.
			 */

			$file_issues_arr = $file_issues_arr_master;

			$file_issues_arr = vipgoci_issues_filter_irrellevant(
				$file_issues_arr,
				$file_changed_lines
			);

                        $file_relevant_lines = @array_flip(
                                $file_changed_lines
                        );


			/*
			 * Loop through array of lines in which
			 * issues exist.
			 */

			foreach (
				$file_issues_arr as
					$file_issue_line => $file_issue_values
			) {
				/*
				 * Loop through each issue for the particular
				 * line.
				 */

				foreach (
					$file_issue_values as
						$file_issue_val_item
				) {

					$commit_issues_submit[
						$pr_item->number
					][] = array(
						'type'		=> 'lint',

						'file_name'	=>
							$file_info->filename,

						'file_line'	=> intval(
							$file_relevant_lines[
								$file_issue_line
							]
						),

						'issue'		=>
							$file_issue_val_item
					);

					$commit_issues_stats[
						$pr_item->number
					]['error']++;
				}
			}
		}

	}


	/*
	 * Reduce memory-usage, as possible.
	 */
	unset( $file_contents );
	unset( $file_issues_arr );
	unset( $file_issues_arr_master );
	unset( $prs_implicated );
	unset( $file_changed_lines );
	unset( $file_issue_values );

	gc_collect_cycles();
}
