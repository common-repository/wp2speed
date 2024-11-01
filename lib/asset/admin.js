(function($)
{
	$(function()
	{
		var $mmr_processed = $('#mmr_processed'),
		$mmr_jsprocessed = $('#mmr_jsprocessed', $mmr_processed),
		$mmr_jsprocessed_ul = $('ul', $mmr_jsprocessed),
		$mmr_cssprocessed = $('#mmr_cssprocessed', $mmr_processed),
		$mmr_cssprocessed_ul = $('ul', $mmr_cssprocessed),
		$mmr_noprocessed = $('#mmr_noprocessed'),
		timeout = null,
		stamp = null;
		
		$mmr_processed.on('click','.log',function(e)
		{
			e.preventDefault();
			$(this).parent().nextAll('pre').slideToggle();
		});
		
		$mmr_processed.on('click','.purge',function(e)
		{
			e.preventDefault();
			clearInterval(timeout);
			getFiles({purge:$(this).attr('href').substr(1)});
			$(this).parent().parent().remove();
		});
		
		$('.purgeall', $mmr_processed).on('click',function(e)
		{
			var btn=$(this);if(btn.data('locked')) return;btn.data('locked',1);	//hoang
			e.preventDefault();
			clearInterval(timeout);
			getFiles({purge:'all'}, function(){
				btn.data('locked',0);
				alert('successful');
			});
			//$mmr_noprocessed.show();	//hoang
			//$mmr_processed.hide();
			$mmr_jsprocessed_ul.html('');
			$mmr_cssprocessed_ul.html('');
		});
		
		function processResponse(response, $ul)
		{
			$(response).each(function(i)
			{
				var $li = $ul.find('li.' + this.hash);
				if($li.length > 0)
				{
					var $filename = $li.find('.filename');
					if($filename.text() != this.filename)
					{
						$filename.html(i+') '+this.filename);
					}
					if($li.find('pre').text() != this.log)
					{
						$li.find('pre').html(this.log);
					}
					if($li.find('.accessed').text() != 'Last Accessed: ' + this.accessed)
					{
						$li.find('.accessed').html('Last Accessed: ' + this.accessed);
					}
					if(this.error)
					{
						$filename.addClass('error');
					}
					else
					{
						$filename.removeClass('error');
					}
				}
				else
				{
					$ul.append('<li class="' + this.hash + '"><span class="filename' + (this.error?' error':'') + '">'+i+') ' + this.filename + '</span> <span class="accessed">Last Accessed: ' + this.accessed + '</span> <span class="actions"><a href="#" class="log button button-primary">View Log</a> <a href="#' + this.hash + '" class="button button-secondary purge">Purge</a></span><pre>' + this.log + '</pre></li>');
				}
			});
		}
		
		function getFiles(extra, cb)
		{
			stamp = new Date().getTime();
			var data = {
				'action': 'mmr_files',
				'stamp': stamp
			};
			if(extra)
			{
				for (var attrname in extra) { data[attrname] = extra[attrname]; }
			}

			$.post(ajaxurl, data, function(response)
			{
				if(stamp == response.stamp) //only update when request is the latest
				{
					if(response.js.length > 0)
					{ 
						$mmr_jsprocessed.show();
						processResponse(response.js, $mmr_jsprocessed_ul);
					}
					else
					{
						//$mmr_jsprocessed.hide();
					}
					
					if(response.css.length > 0)
					{
						$mmr_cssprocessed.show();
						processResponse(response.css, $mmr_cssprocessed_ul);
					}
					else
					{
						//$mmr_cssprocessed.hide();
					}
					
					if(response.js.length == 0 && response.css.length == 0)
					{
						$mmr_noprocessed.show();
						//$mmr_processed.hide();
					}
					else
					{
						//$mmr_noprocessed.hide();
						$mmr_processed.show();
					}
					
					clearInterval(timeout);
					timeout = setTimeout(getFiles, 5000);
				}
				if(data.purge=='all' && cb) cb();
			});
		}
		getFiles();
	});
	jQuery(document).ready(function($){
	  $('.nav-tab-wrapper a.nav-tab').click(function(e){
	    e.preventDefault();
	    var tb=$(this), id=tb.attr('id').replace('-tab','');
	    //show tab
	    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
	    tb.addClass('nav-tab-active');

	    //show content
	    $('.w2ptab').removeClass('active');
	    $('.w2ptab#'+id).addClass('active');

	    if (window.history.pushState) {
	    	history.pushState({}, '', '#'+id);
	    }
	    else {
	    	window.location.hash = id;
	    }
	    var ref=$('form#w2p_options input[name="_wp_http_referer"]');
	    ref.val(ref.val().split('#')[0]+'#'+id);
	    return false;
	  });

	  //check license
	  var lic=$('input[name="w2p-code"]').val();
	  if(lic)
	  $.getJSON('https://ppasset.vercel.app/license-check/'+lic).done(function(dt){
	  	if(!dt.domain || dt.domain!=location.hostname) {
	  		$('#w2p-license-info').html('Wrong License');
	  	}
	  	else $('#w2p-license-info').html('Licensed. Support date: '+(dt.expired? 'Expired':dt.date));
	  }).error(function(){
	  	$('#w2p-license-info').html('Invalid License');	//<!-- Failed to parse your license. -->
	  });

	  switch(window.location.hash) {
	  	case '#file': jQuery('#w2p-tabs .nav-tab').eq(0).click();break;
	  	case '#lic': jQuery('#w2p-tabs .nav-tab').eq(1).click();break;
	  }

	});
})(jQuery);